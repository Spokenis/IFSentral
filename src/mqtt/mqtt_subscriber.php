#!/usr/bin/env php
<?php
/**
 * mqtt_subscriber.php - Worker que subscreve tópicos MQTT
 * Execute em background: php src/mqtt/mqtt_subscriber.php &
 * 
 * Este worker:
 * - Conecta ao broker MQTT
 * - Subscreve tópicos de projetos e dispositivos
 * - Processa payloads recebidos
 * - Salva no banco de dados
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('SRC_DIR', ROOT_DIR . '/src');
define('CONFIG_DIR', SRC_DIR . '/config');

// Carrega autoload do Composer (inclui PhpMqtt)
require_once ROOT_DIR . '/vendor/autoload.php';

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/mqtt.php';
require_once CONFIG_DIR . '/db.php';
require_once SRC_DIR . '/core/PayloadHandler.php';
require_once SRC_DIR . '/core/RateLimiter.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Core\PayloadHandler;
use App\Core\RateLimiter;

// Log function
function mqtt_log($message, $level = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    $log_dir = __DIR__ . '/../../logs';
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/mqtt_subscriber.log';
    $log_message = "[$timestamp] [$level] $message\n";
    
    echo $log_message;  // Também mostra no console
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

mqtt_log('=== MQTT Subscriber iniciado ===', 'INFO');
mqtt_log("Conectando a: " . MQTT_HOST . ":" . MQTT_PORT, 'INFO');

// Inicializa RateLimiter
$rateLimiter = new RateLimiter($conn);

try {
    // Configurações de conexão
    $connectionSettings = new ConnectionSettings();
    $connectionSettings
        ->setKeepAliveInterval(MQTT_KEEP_ALIVE)
        ->setConnectTimeout(5)
        ->setUseTls(false)
        ->setUsername(MQTT_USERNAME)
        ->setPassword(MQTT_PASSWORD);

    // Cria cliente MQTT
    $client = new MqttClient(MQTT_HOST, MQTT_PORT, MQTT_CLIENT_ID);

    // Callback para mensagens recebidas
    $client->registerLoopEventHandler(function (MqttClient $client, $elapsed) {
        // Permite que o cliente processe mensagens
        // Este callback é chamado a cada iteração do loop
    });

    // Conecta ao broker
    $client->connect($connectionSettings);
    mqtt_log('Conectado ao broker MQTT com sucesso', 'INFO');

    // Subscreve tópicos
    foreach (MQTT_TOPICS as $topic) {
        $client->subscribe($topic, function (
            string $topic,
            string $message,
            bool $retained
        ) use ($conn) {
            mqtt_log("Mensagem recebida no tópico: $topic", 'DEBUG');
            
            // Parse do tópico: mqtt/projects/{project_id}/devices/{device_id}
            $parts = explode('/', $topic);
            
            if (count($parts) >= 5 && $parts[0] === 'mqtt' && $parts[1] === 'projects' && $parts[3] === 'devices') {
                $project_id = intval($parts[2]);
                $device_id = intval($parts[4]);
                
                // Descriptografa a mensagem (se for necessário)
                $payload_data = json_decode($message, true);
                
                if ($payload_data === null && json_last_error() !== JSON_ERROR_NONE) {
                    mqtt_log("Erro ao fazer parse do JSON no tópico $topic: " . json_last_error_msg(), 'ERROR');
                    return;
                }
                
                // Se não for JSON, trata como string simples
                if ($payload_data === null) {
                    $payload_data = ['value' => $message];
                }
                
                // Aqui precisamos da API Key do dispositivo
                // Buscamos no banco
                $apiKeySql = "SELECT api_key FROM devices WHERE id = ? AND project_id = ?";
                try {
                    $apiKeyStmt = $conn->prepare($apiKeySql);
                    $apiKeyStmt->execute([$device_id, $project_id]);
                    
                    if ($apiKeyStmt->rowCount() > 0) {
                        $device = $apiKeyStmt->fetch(\PDO::FETCH_ASSOC);
                        $api_key = $device['api_key'];
                        
                        // Verifica rate limit ANTES de processar
                        $rateCheck = $rateLimiter->checkLimit($device_id, 'mqtt', 'mqtt_local');
                        
                        if (!$rateCheck['allowed']) {
                            mqtt_log("Rate limit excedido - Device: $device_id, Requests: {$rateCheck['requests']}, Limit: {$rateCheck['limit']}", 'WARN');
                            return;  // Descarta a mensagem
                        }
                        
                        // Usa o PayloadHandler para salvar
                        $handler = new PayloadHandler($conn);
                        $result = $handler->savePayload($device_id, $api_key, $payload_data, 'mqtt');
                        
                        if ($result['success']) {
                            mqtt_log("Payload salvo com sucesso - ID: {$result['id']}, Device: $device_id, Project: $project_id, Requests: {$rateCheck['requests']}/{$rateCheck['limit']}", 'INFO');
                        } else {
                            mqtt_log("Erro ao salvar payload: {$result['message']}", 'ERROR');
                        }
                    } else {
                        mqtt_log("Dispositivo não encontrado - Device ID: $device_id, Project ID: $project_id", 'WARN');
                    }
                } catch (\Exception $e) {
                    mqtt_log("Exceção ao processar payload: " . $e->getMessage(), 'ERROR');
                }
            } else {
                mqtt_log("Formato de tópico inválido: $topic", 'WARN');
            }
        }, 1);  // QoS 1
    }

    mqtt_log('Subscrito aos tópicos com sucesso', 'INFO');
    mqtt_log('Aguardando mensagens...', 'INFO');

    // Loop para manter a conexão ativa
    $reconnect_attempts = 0;
    while (true) {
        try {
            $client->loop(true);  // blocking = true
        } catch (\Exception $e) {
            mqtt_log("Erro no loop: " . $e->getMessage(), 'ERROR');
            
            $reconnect_attempts++;
            if ($reconnect_attempts > MQTT_MAX_RECONNECT_ATTEMPTS) {
                mqtt_log("Máximo de tentativas de reconexão atingido. Encerrando.", 'ERROR');
                break;
            }
            
            mqtt_log("Tentando reconectar em " . MQTT_RECONNECT_DELAY . "s (tentativa $reconnect_attempts)...", 'INFO');
            sleep(MQTT_RECONNECT_DELAY);
            
            try {
                $client->connect($connectionSettings);
                mqtt_log("Reconectado com sucesso", 'INFO');
                $reconnect_attempts = 0;
            } catch (\Exception $e) {
                mqtt_log("Falha na reconexão: " . $e->getMessage(), 'ERROR');
            }
        }
    }

} catch (\Exception $e) {
    mqtt_log("Erro fatal: " . $e->getMessage(), 'ERROR');
    mqtt_log("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    exit(1);
}

// Encerramento limpo
mqtt_log('=== MQTT Subscriber encerrado ===', 'INFO');
exit(0);
?>

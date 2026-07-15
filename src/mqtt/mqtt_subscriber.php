#!/usr/bin/env php
<?php
/**
 * mqtt_subscriber.php - Worker que subscreve tópicos MQTT
 * Execute em background: php src/mqtt/mqtt_subscriber.php &
 * * Este worker:
 * - Conecta ao broker MQTT
 * - Subscreve tópicos usando Shared Subscriptions (Balanceamento de Carga)
 * - Mantém e recupera conexões perdidas com o banco de dados (Ping/Reconnect)
 * - Processa payloads recebidos e salva no banco de dados
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

// ==========================================
// FUNÇÃO DE PROTEÇÃO DO BANCO DE DADOS
// ==========================================
function ensureDbConnection(&$conn) {
    try {
        // Tenta executar uma query levíssima para testar a conexão
        $conn->query('SELECT 1');
        return true;
    } catch (\PDOException $e) {
        mqtt_log("Conexão com o banco perdida: " . $e->getMessage() . ". Tentando reconectar...", 'WARN');
        
        $max_retries = 3;
        for ($i = 1; $i <= $max_retries; $i++) {
            try {
                // Reconstrói a conexão usando as constantes do seu config/db.php
                $conn = new \PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
                    DB_USER, 
                    DB_PASS,
                    [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        \PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
                mqtt_log("Banco de dados reconectado com sucesso na tentativa $i.", 'INFO');
                return true;
            } catch (\PDOException $err) {
                mqtt_log("Falha ao reconectar (tentativa $i/$max_retries). Aguardando 2s...", 'ERROR');
                sleep(2);
            }
        }
        
        // Se falhar completamente, encerra o processo limpo para que o Supervisor/Systemd reinicie
        mqtt_log("Falha crítica ao recuperar o banco de dados. Encerrando worker.", 'FATAL');
        exit(1);
    }
}
// ==========================================

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

    // CRÍTICO: Cria ID de cliente único para permitir múltiplas instâncias
    $unique_client_id = MQTT_CLIENT_ID . '_' . getmypid() . '_' . uniqid();
    
    // Cria cliente MQTT
    $client = new MqttClient(MQTT_HOST, MQTT_PORT, $unique_client_id);

    // Callback para mensagens recebidas
    $client->registerLoopEventHandler(function (MqttClient $client, $elapsed) {
        // Callback acionado a cada iteração do loop interno
    });

    // Conecta ao broker
    $client->connect($connectionSettings);
    mqtt_log('Conectado ao broker MQTT com sucesso (ID: ' . $unique_client_id . ')', 'INFO');

    // Nome do grupo de balanceamento (Shared Subscription)
    $worker_group = 'cluster_db';

    // Subscreve tópicos
    foreach (MQTT_TOPICS as $topic) {
        
        // Adiciona o prefixo de assinatura compartilhada do Mosquitto
        $shared_topic = '$share/' . $worker_group . '/' . $topic;
        
        // CRÍTICO: Passagem por referência (&$conn, &$rateLimiter)
        $client->subscribe($shared_topic, function (
            string $real_topic,
            string $message,
            bool $retained
        ) use (&$conn, &$rateLimiter) {
            mqtt_log("Mensagem recebida no tópico: $real_topic", 'DEBUG');
            
            $parts = explode('/', $real_topic);
            
            if (count($parts) >= 5 && $parts[0] === 'mqtt' && $parts[1] === 'projects' && $parts[3] === 'devices') {
                $project_id = intval($parts[2]);
                $device_id = intval($parts[4]);
                
                $payload_data = json_decode($message, true);
                
                if ($payload_data === null && json_last_error() !== JSON_ERROR_NONE) {
                    mqtt_log("Erro ao fazer parse do JSON no tópico $real_topic: " . json_last_error_msg(), 'ERROR');
                    return;
                }
                
                if ($payload_data === null) {
                    $payload_data = ['value' => $message];
                }
                
                // --- PROTEÇÃO DO BANCO DE DADOS ---
                // Verifica a vitalidade e reconecta se necessário antes de qualquer transação
                ensureDbConnection($conn);
                
                // Se a conexão foi recriada, precisamos atualizar o limitador com a nova instância
                $rateLimiter = new RateLimiter($conn);
                // ----------------------------------
                
                $apiKeySql = "SELECT api_key FROM devices WHERE id = ? AND project_id = ?";
                try {
                    $apiKeyStmt = $conn->prepare($apiKeySql);
                    $apiKeyStmt->execute([$device_id, $project_id]);
                    
                    if ($apiKeyStmt->rowCount() > 0) {
                        $device = $apiKeyStmt->fetch(\PDO::FETCH_ASSOC);
                        $api_key = $device['api_key'];
                        
                        $rateCheck = $rateLimiter->checkLimit($device_id, 'mqtt', 'mqtt_local');
                        
                        if (!$rateCheck['allowed']) {
                            mqtt_log("Rate limit excedido - Device: $device_id, Requests: {$rateCheck['requests']}, Limit: {$rateCheck['limit']}", 'WARN');
                            return; 
                        }
                        
                        // Passamos a conexão garantida para o handler
                        $handler = new PayloadHandler($conn);
                        $result = $handler->savePayload($device_id, $api_key, $payload_data, 'mqtt');
                        
                        if ($result['success']) {
                            mqtt_log("Payload salvo com sucesso - ID: {$result['id']}, Device: $device_id, Project: $project_id", 'INFO');
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
                mqtt_log("Formato de tópico inválido: $real_topic", 'WARN');
            }
        }, 1);  // QoS 1
    }

    mqtt_log('Subscrito aos tópicos com sucesso', 'INFO');
    mqtt_log('Aguardando mensagens...', 'INFO');

    // Loop para manter a conexão ativa
    $reconnect_attempts = 0;
    while (true) {
        try {
            $client->loop(true);
        } catch (\Exception $e) {
            mqtt_log("Erro no loop: " . $e->getMessage(), 'ERROR');
            
            $reconnect_attempts++;
            if ($reconnect_attempts > MQTT_MAX_RECONNECT_ATTEMPTS) {
                mqtt_log("Máximo de tentativas de reconexão atingido. Encerrando.", 'ERROR');
                break;
            }
            
            mqtt_log("Tentando reconectar MQTT em " . MQTT_RECONNECT_DELAY . "s (tentativa $reconnect_attempts)...", 'INFO');
            sleep(MQTT_RECONNECT_DELAY);
            
            try {
                $client->connect($connectionSettings);
                mqtt_log("Reconectado ao broker com sucesso", 'INFO');
                $reconnect_attempts = 0;
            } catch (\Exception $e) {
                mqtt_log("Falha na reconexão MQTT: " . $e->getMessage(), 'ERROR');
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
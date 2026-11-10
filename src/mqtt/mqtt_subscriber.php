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
use PhpMqtt\Client\Message;
use PhpMqtt\Client\MessageType;
use PhpMqtt\Client\ConnectionSettings;

/**
 * Cliente MQTT que só confirma (PUBACK) uma mensagem QoS 1 DEPOIS que ela foi
 * processada. A biblioteca confirma antes de entregar a mensagem ao código:
 * se o banco caísse ou o worker morresse nesse meio-tempo, o broker já tinha
 * descartado a mensagem e ela se perdia (teste de falhas de 09/11/2026: 400
 * leituras perdidas com o banco 10 s sem gravar). Agora, se a gravação falhar
 * por um problema passageiro, o callback encerra o processo sem confirmar, o
 * Docker sobe outro worker e o broker reentrega a mensagem (sessão persistente).
 * Efeito colateral aceito: se o worker morrer entre gravar e confirmar, a
 * mensagem é gravada de novo (entrega "pelo menos uma vez", como o QoS 1 define).
 */
class ClienteConfirmaDepoisDeGravar extends MqttClient
{
    protected function handleMessage(Message $message): void
    {
        if ($message->getType()->equals(MessageType::PUBLISH())
            && $message->getQualityOfService() === self::QOS_AT_LEAST_ONCE) {
            $this->deliverPublishedMessage($message->getTopic(), $message->getContent(), $message->getQualityOfService(), $message->getRetained());
            $this->sendPublishAcknowledgement($message->getMessageId());
            return;
        }
        parent::handleMessage($message);
    }
}

/** Falha passageira na gravação: encerra SEM confirmar, para o broker reentregar */
function encerrarSemConfirmar(string $motivo): void
{
    mqtt_log("Falha passageira ao gravar ($motivo). Encerrando sem confirmar a mensagem; o broker vai reentregá-la ao worker que o Docker subir.", 'ERROR');
    exit(1);
}
use PhpMqtt\Client\Repositories\MemoryRepository;
use PhpMqtt\Client\Subscription;
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
    // Rotação: no teste de 10 h (20 msg/s) o arquivo chegou a 100 MB. Ao passar
    // de 10 MB vira .1 (e o .1 vira .2, ... até .3); ocupa no máximo ~40 MB.
    static $verificado = 0;
    if (time() - $verificado >= 60) {
        $verificado = time();
        clearstatcache(true, $log_file);
        if (is_file($log_file) && filesize($log_file) > 10 * 1024 * 1024) {
            for ($i = 2; $i >= 1; $i--) {
                if (is_file("$log_file.$i")) {
                    @rename("$log_file.$i", "$log_file." . ($i + 1));
                }
            }
            @rename($log_file, "$log_file.1");
        }
    }
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Anuncia que o worker está online no mesmo tópico usado pelo Last Will
// (retain=true), completando o par online/offline. O LWT sozinho só cobre a
// queda inesperada; sem isso, o último estado retido após um restart normal
// continuaria sendo "offline" do processo anterior.
function publishWorkerOnlineStatus(MqttClient $client, string $topic, string $workerId)
{
    $payload = json_encode([
        'status' => 'online',
        'worker_id' => $workerId,
        'pid' => getmypid(),
    ]);
    $client->publish($topic, $payload, 1, true);
}

// ==========================================
// FUNÇÃO DE PROTEÇÃO DO BANCO DE DADOS
// ==========================================
// Devolve true se a conexão precisou ser recriada (quem usa a antiga, como o
// RateLimiter, deve ser recriado também)
function ensureDbConnection(&$conn) {
    try {
        // Tenta executar uma query levíssima para testar a conexão
        $conn->query('SELECT 1');
        return false;
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
                $conn->exec("SET time_zone = '" . date('P') . "'"); // mesmo fuso de db.php
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
    mqtt_log('MQTT credentials: user=' . (MQTT_USERNAME ?: '(null)') . ' password_set=' . (MQTT_PASSWORD ? 'yes' : 'no'), 'DEBUG');
    $connectionSettings = new ConnectionSettings();
    // TLS fica desligado aqui de propósito: este worker conversa com o broker
    // dentro da mesma rede Docker (container-a-container), não atravessa rede
    // não confiável. O listener 8883/TLS existe para dispositivos externos
    // (ver mosquitto.conf e README).
    // ConnectionSettings é imutável: cada setter retorna uma cópia nova, então
    // o encadeamento precisa ser reatribuído — sem isso $connectionSettings
    // continua sendo o objeto original vazio (sem usuário/senha/keep-alive),
    // e o broker recebe um CONNECT anônimo, rejeitado com "not authorized".
    $connectionSettings = $connectionSettings
        ->setKeepAliveInterval(MQTT_KEEP_ALIVE)
        ->setConnectTimeout(5)
        ->setUseTls(false)
        ->setUsername(MQTT_USERNAME)
        ->setPassword(MQTT_PASSWORD);

    // Um ID por instância (hostname do contêiner) permite várias instâncias e
    // mantém o mesmo ID entre reinícios — ver MQTT_CLIENT_ID em config/mqtt.php
    $unique_client_id = MQTT_CLIENT_ID;

    // Last Will and Testament: se este worker cair sem se desconectar
    // corretamente (crash, rede, OOM-kill), o broker publica esta mensagem
    // por ele. Tópico é por-instância (inclui o client_id) — com múltiplos
    // workers rodando em paralelo (shared subscription), um tópico
    // compartilhado faria o "online" de um worker vivo ser sobrescrito pelo
    // "offline" de outro que morreu, com retain=true.
    $lwt_topic = 'ifsentral/workers/' . $unique_client_id . '/status';
    $lwt_offline_payload = json_encode([
        'status' => 'offline',
        'worker_id' => $unique_client_id,
        'reason' => 'unexpected_disconnect',
    ]);

    $connectionSettings = $connectionSettings
        ->setLastWillTopic($lwt_topic)
        ->setLastWillMessage($lwt_offline_payload)
        ->setLastWillQualityOfService(1)
        ->setRetainLastWill(true);

    // Nome do grupo de balanceamento (Shared Subscription)
    $worker_group = 'cluster_db';

    // Tratamento de cada mensagem recebida.
    // CRÍTICO: Passagem por referência (&$conn, &$rateLimiter)
    $onMessage = function (
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

            // Envelope opcional para leituras guardadas pelo dispositivo enquanto
            // estava sem rede: {"timestamp": <instante da medição, s Unix>,
            // "id": "<identificador da leitura>", "payload": {...}}. O mesmo id
            // reenviado não grava de novo. Mensagem sem envelope: como sempre.
            $instanteMedicao = null;
            $idLeitura = null;
            if (is_array($payload_data) && isset($payload_data['payload']) && is_array($payload_data['payload'])
                && !array_diff(array_keys($payload_data), ['payload', 'timestamp', 'id'])) {
                $ts = $payload_data['timestamp'] ?? null;
                $idEnv = $payload_data['id'] ?? null;
                if (($ts !== null && (!is_numeric($ts) || (int) $ts < time() - 7 * 86400 || (int) $ts > time() + 300))
                    || ($idEnv !== null && (!is_string($idEnv) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $idEnv)))) {
                    mqtt_log("Envelope inválido no tópico $real_topic (timestamp de até 7 dias atrás; id com até 100 caracteres) - descartado", 'WARN');
                    return;
                }
                $instanteMedicao = $ts !== null ? (int) $ts : null;
                $idLeitura = $idEnv;
                $payload_data = $payload_data['payload'];
            }

            // --- PROTEÇÃO DO BANCO DE DADOS ---
            // Verifica a vitalidade e reconecta se necessário antes de qualquer transação.
            // O RateLimiter lê as configurações do banco ao ser criado: antes era
            // recriado a cada mensagem (uma consulta a mais por leitura); agora só
            // quando a conexão muda ou a cada 60 s (para valer o que o admin
            // mudar no painel).
            static $limiterCriadoEm = 0;
            if (ensureDbConnection($conn) || time() - $limiterCriadoEm >= 60) {
                $rateLimiter = new RateLimiter($conn);
                $limiterCriadoEm = time();
            }
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
                        // Uma linha de log por dispositivo a cada minuto (antes era
                        // uma por mensagem recusada: milhares por minuto)
                        static $ultimoAvisoLimite = [];
                        if (time() - ($ultimoAvisoLimite[$device_id] ?? 0) >= 60) {
                            mqtt_log("Rate limit excedido - Device: $device_id, Requests: {$rateCheck['requests']}, Limit: {$rateCheck['limit']} (mensagens acima do limite são descartadas; próximo aviso em 1 min)", 'WARN');
                            $ultimoAvisoLimite[$device_id] = time();
                        }
                        return;
                    }

                    // Passamos a conexão garantida para o handler
                    $handler = new PayloadHandler($conn);

                    // Mesma validação de estrutura (profundidade/tamanho/nº de chaves)
                    // já aplicada no caminho HTTP em enviar_payload.php — sem isso,
                    // payloads MQTT passavam sem nenhum limite de tamanho/complexidade.
                    $validation = $handler->validatePayload($payload_data, $device_id);
                    if (!$validation['valid']) {
                        mqtt_log("Payload MQTT rejeitado (inválido) - Device: $device_id: " . implode('; ', $validation['errors']), 'WARN');
                        return;
                    }

                    $result = $handler->savePayload($device_id, $api_key, $payload_data, 'mqtt', $instanteMedicao, $idLeitura);

                    if (!$result['success'] && !empty($result['erro_transitorio'])) {
                        encerrarSemConfirmar('device ' . $device_id);
                    }
                    if ($result['success']) {
                        mqtt_log("Payload salvo com sucesso - ID: {$result['id']}, Device: $device_id, Project: $project_id", 'INFO');
                    } else {
                        mqtt_log("Erro ao salvar payload: {$result['message']}", 'ERROR');
                    }
                } else {
                    mqtt_log("Dispositivo não encontrado - Device ID: $device_id, Project ID: $project_id", 'WARN');
                }
            } catch (\PDOException $e) {
                // Banco fora no meio do processamento: não confirmar
                if (PayloadHandler::erroTransitorio($e)) {
                    encerrarSemConfirmar($e->getMessage());
                }
                mqtt_log("Erro de banco ao processar payload (mensagem descartada): " . $e->getMessage(), 'ERROR');
            } catch (\Exception $e) {
                mqtt_log("Exceção ao processar payload: " . $e->getMessage(), 'ERROR');
            }
        } else {
            mqtt_log("Formato de tópico inválido: $real_topic", 'WARN');
        }
    };

    // A sessão é persistente: ao reconectar, o broker entrega logo após o
    // CONNACK as mensagens que guardou enquanto o worker estava fora. A
    // biblioteca só ativa uma assinatura quando chega o SUBACK, e descarta
    // (já confirmando ao broker) o que chega antes — por isso as assinaturas
    // são registradas no repositório antes de conectar.
    // Assinatura COMUM (não $share/...): o Mosquitto guarda as assinaturas
    // comuns da sessão persistente e as restaura quando reinicia; as
    // compartilhadas, não — no teste de falhas de 09/11/2026, reiniciar o
    // broker perdia tudo o que chegava até o worker assinar de novo (293
    // leituras). Por isso há um worker só (dois com assinatura comum gravariam
    // cada leitura duas vezes); ele grava ~250 msg/s, bem acima do necessário.
    $repository = new MemoryRepository();
    foreach (MQTT_TOPICS as $topic) {
        $repository->addSubscription(new Subscription($topic, 1, $onMessage));
    }

    // Cria cliente MQTT
    $client = new ClienteConfirmaDepoisDeGravar(MQTT_HOST, MQTT_PORT, $unique_client_id, MqttClient::MQTT_3_1, $repository);

    // Callback para mensagens recebidas
    // Batimento para o healthcheck do Docker (docker-compose.yml): o laço
    // toca /tmp/worker-vivo no máximo a cada 30 s. Se o laço travar, o
    // arquivo envelhece e o contêiner fica "unhealthy".
    @touch('/tmp/worker-vivo');
    $client->registerLoopEventHandler(function (MqttClient $client, $elapsed) {
        static $ultimoBatimento = 0;
        if (time() - $ultimoBatimento >= 30) {
            @touch('/tmp/worker-vivo');
            $ultimoBatimento = time();
        }
    });

    // Conecta ao broker
    $client->connect($connectionSettings);
    mqtt_log('Conectado ao broker MQTT com sucesso (ID: ' . $unique_client_id . ')', 'INFO');
    publishWorkerOnlineStatus($client, $lwt_topic, $unique_client_id);

    // Subscreve tópicos
    foreach (MQTT_TOPICS as $topic) {
        // Versões anteriores assinavam $share/cluster_db/...; a sessão
        // persistente guardaria as duas assinaturas e cada mensagem chegaria
        // duas vezes. Remover a antiga é inofensivo se ela não existir.
        $client->unsubscribe('$share/' . $worker_group . '/' . $topic);
        $client->subscribe($topic, $onMessage, 1);  // QoS 1
    }

    mqtt_log('Subscrito aos tópicos com sucesso', 'INFO');
    mqtt_log('Aguardando mensagens...', 'INFO');

    // Loop para manter a conexão ativa.
    //
    // Se a conexão com o broker cai (ex.: o mosquitto foi reiniciado), o
    // processo ENCERRA e o Docker sobe outro (restart: unless-stopped). Antes
    // ele reconectava aqui mesmo, mas sem refazer a assinatura $share/...: ficava
    // "conectado" e não recebia mais nada até alguém reiniciar o worker à mão.
    // Refazer a assinatura neste processo duplicaria o registro no repositório
    // (cada mensagem seria gravada duas vezes); o processo novo já começa com a
    // assinatura pré-registrada e a sessão persistente traz o que ficou na fila.
    try {
        $client->loop(true);
    } catch (\Exception $e) {
        mqtt_log("Conexão com o broker perdida: " . $e->getMessage() . ". Encerrando para o Docker reiniciar o worker.", 'ERROR');
        sleep(2); // não martela o broker se ele ainda estiver subindo
        exit(1);
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
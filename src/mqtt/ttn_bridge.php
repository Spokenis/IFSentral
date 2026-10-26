#!/usr/bin/env php
<?php
/**
 * ttn_bridge.php - Ponte entre o The Things Network e o IFSCentral
 * Roda no contêiner ttn-bridge (ver docker-compose.yml): php src/mqtt/ttn_bridge.php
 *
 * Para cada projeto com integração TTN ativa (tabela ttn_applications):
 * - conecta ao servidor MQTT do TTN (TLS, porta 8883) e grava cada uplink
 *   assim que ele chega. É o servidor que abre a conexão, então funciona sem
 *   que o TTN consiga acessar o IFSCentral (ao contrário do webhook);
 * - a cada BACKFILL_INTERVAL, busca na Storage Integration o que chegou
 *   enquanto a conexão esteve fora. O MQTT do TTN só tem QoS 0 e não guarda
 *   mensagens para quem está desconectado; o Storage guarda por 24 horas.
 *
 * Um uplink que chega pelos dois caminhos é gravado uma vez só (TtnSync).
 */

define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('SRC_DIR', ROOT_DIR . '/src');
define('CONFIG_DIR', SRC_DIR . '/config');

require_once ROOT_DIR . '/vendor/autoload.php';
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';
require_once SRC_DIR . '/core/TtnSync.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Core\TtnSync;

const RELOAD_INTERVAL = 60;     // relê ttn_applications (aplicação nova, chave trocada, desativada)
const BACKFILL_INTERVAL = 300;  // busca no Storage o que a conexão MQTT perdeu
const TTN_MQTT_PORT = 8883;

function bridge_log($message, $level = 'INFO')
{
    echo '[' . date('Y-m-d H:i:s') . "] [$level] $message\n";
}

function load_applications(PDO $conn)
{
    $stmt = $conn->query("
        SELECT ta.*
        FROM ttn_applications ta
        JOIN projects p ON p.id = ta.project_id AND p.deletedAt IS NULL
        WHERE ta.enabled = 1");
    $apps = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $app) {
        $apps[(int)$app['project_id']] = $app;
    }
    return $apps;
}

/** Muda quando algo que exige reconectar muda */
function connection_signature(array $app)
{
    return $app['cluster'] . '|' . $app['application_id'] . '|' . $app['api_key'];
}

function connect_application(array $app, TtnSync $sync)
{
    // Usuário do MQTT do TTN: {id da aplicação}@{tenant}; no TTN público o tenant é "ttn"
    $settings = (new ConnectionSettings())
        ->setUsername($app['application_id'] . '@ttn')
        ->setPassword($app['api_key'])
        ->setUseTls(true)
        ->setTlsVerifyPeer(true)
        ->setTlsVerifyPeerName(true)
        ->setConnectTimeout(10)
        ->setKeepAliveInterval(60);

    $project_id = (int)$app['project_id'];
    $topic = 'v3/' . $app['application_id'] . '@ttn/devices/+/up';

    $client = new MqttClient(
        $app['cluster'], TTN_MQTT_PORT,
        'ifscentral-ttn-' . $project_id . '-' . gethostname(),
        MqttClient::MQTT_3_1_1
    );
    // Sessão limpa: o TTN só entrega QoS 0 e não guarda nada para depois
    $client->connect($settings, true);
    $client->subscribe($topic, function (string $topic, string $message) use ($sync, $project_id) {
        $uplink = json_decode($message, true);
        if (!is_array($uplink)) {
            bridge_log("Projeto $project_id: mensagem que não é JSON em $topic", 'WARN');
            return;
        }
        $result = $sync->ingestUplink($project_id, $uplink);
        $device = $uplink['end_device_ids']['device_id'] ?? '?';
        bridge_log("Projeto $project_id: uplink de '$device' -> $result", $result === 'saved' ? 'INFO' : 'DEBUG');
    }, 0);

    return $client;
}

function record_error(PDO $conn, $project_id, $message)
{
    $conn->prepare("UPDATE ttn_applications SET last_error = ? WHERE project_id = ?")
        ->execute([mb_substr($message, 0, 255), $project_id]);
}

bridge_log('=== Ponte TTN iniciada ===');

$sync = new TtnSync($conn);
$clients = [];      // project_id => ['client' => MqttClient, 'signature' => string]
$apps = [];
$lastReload = 0;
$lastBackfill = []; // project_id => time()

try {
    while (true) {
        $now = time();

        if ($now - $lastReload >= RELOAD_INTERVAL) {
            $lastReload = $now;
            $apps = load_applications($conn);

            // Desconecta aplicações removidas, desativadas ou alteradas
            foreach ($clients as $project_id => $entry) {
                if (!isset($apps[$project_id]) || connection_signature($apps[$project_id]) !== $entry['signature']) {
                    try {
                        $entry['client']->disconnect();
                    } catch (\Exception $e) {
                        // a conexão já pode ter caído
                    }
                    unset($clients[$project_id]);
                    bridge_log("Projeto $project_id: desconectado do TTN (configuração mudou ou foi removida)");
                }
            }

            // Conecta as que faltam (as que falharam são tentadas de novo no próximo ciclo)
            foreach ($apps as $project_id => $app) {
                if (isset($clients[$project_id])) {
                    continue;
                }
                try {
                    $clients[$project_id] = [
                        'client' => connect_application($app, $sync),
                        'signature' => connection_signature($app),
                    ];
                    bridge_log("Projeto $project_id: conectado ao TTN ({$app['application_id']} em {$app['cluster']})");
                    $lastBackfill[$project_id] = 0; // recupera logo o que chegou enquanto estava fora
                } catch (\Exception $e) {
                    bridge_log("Projeto $project_id: falha ao conectar ao MQTT do TTN: " . $e->getMessage(), 'ERROR');
                    record_error($conn, $project_id, 'MQTT do TTN: ' . $e->getMessage());
                }
            }
        }

        foreach ($apps as $project_id => $app) {
            if ($now - ($lastBackfill[$project_id] ?? 0) < BACKFILL_INTERVAL) {
                continue;
            }
            $lastBackfill[$project_id] = $now;
            $fresh = $sync->findApplication($project_id); // last_received_at atualizado
            if (!$fresh || !$fresh['enabled']) {
                continue;
            }
            $result = $sync->syncApplication($fresh);
            if (isset($result['error'])) {
                bridge_log("Projeto $project_id: Storage do TTN: {$result['error']}", 'ERROR');
            } elseif (!empty($result['saved'])) {
                bridge_log("Projeto $project_id: Storage do TTN recuperou {$result['saved']} uplink(s)");
            }
        }

        if (!$clients) {
            sleep(1);
            continue;
        }

        foreach ($clients as $project_id => $entry) {
            try {
                $entry['client']->loopOnce(microtime(true), true, intdiv(100000, count($clients)));
            } catch (\Exception $e) {
                bridge_log("Projeto $project_id: conexão com o TTN caiu: " . $e->getMessage(), 'ERROR');
                unset($clients[$project_id]);
                $lastReload = 0; // reconecta no próximo ciclo
            }
        }
    }
} catch (\PDOException $e) {
    // Banco fora do ar: encerra e deixa o Docker reiniciar o contêiner
    bridge_log('Erro de banco de dados: ' . $e->getMessage(), 'ERROR');
    exit(1);
}

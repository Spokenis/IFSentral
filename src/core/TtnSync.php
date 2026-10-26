<?php
/**
 * TtnSync.php - Grava no IFSCentral os uplinks de uma aplicação do TTN
 *
 * Cada projeto pode ter uma aplicação do TTN (tabela ttn_applications), e cada
 * dispositivo do projeto aponta para um end device dela (devices.ttn_device_id).
 *
 * - syncApplication(): busca na Storage Integration o que chegou depois do
 *   último uplink já lido (last_received_at) e grava. É o único caminho que
 *   avança esse ponto, porque é o único que lê tudo em ordem.
 * - ingestUplink(): grava um uplink avulso — usado pela busca acima e pela
 *   ponte MQTT da edição completa (src/mqtt/ttn_bridge.php).
 *
 * O mesmo uplink pode chegar pelos dois caminhos (e pelo webhook): ele é
 * gravado uma vez só, pela chave única device_id + external_id.
 */

namespace App\Core;

require_once __DIR__ . '/TtnClient.php';
require_once __DIR__ . '/PayloadHandler.php';

class TtnSync
{
    const MIN_INTERVAL = 60;  // segundos entre buscas automáticas da mesma aplicação
    const PAGE_SIZE = 100;
    const MAX_PAGES = 10;     // até 1000 uplinks por busca; o resto fica para a próxima
    // A 1ª página de cada busca recomeça um pouco antes do último uplink lido:
    // dois uplinks quase simultâneos podem entrar no Storage fora de ordem, e o
    // mais antigo ficaria para trás do ponto de leitura. Os repetidos são
    // descartados pela chave única (external_id).
    const OVERLAP_SECONDS = 120;

    private $conn;

    public function __construct(\PDO $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Busca as aplicações ativas cuja última busca tem mais de MIN_INTERVAL
     * segundos. Chamado a cada acesso às páginas no Lite (api/ttn-sincronizar).
     */
    public function syncDue($max_applications = 3)
    {
        $stmt = $this->conn->prepare("
            SELECT ta.*
            FROM ttn_applications ta
            JOIN projects p ON p.id = ta.project_id AND p.deletedAt IS NULL
            WHERE ta.enabled = 1
              AND (ta.last_sync_at IS NULL OR ta.last_sync_at < DATE_SUB(NOW(), INTERVAL " . self::MIN_INTERVAL . " SECOND))
            ORDER BY ta.last_sync_at IS NOT NULL, ta.last_sync_at
            LIMIT " . (int)$max_applications);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $app) {
            $results[$app['project_id']] = $this->syncApplication($app);
        }
        return $results;
    }

    public function findApplication($project_id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM ttn_applications WHERE project_id = ?");
        $stmt->execute([$project_id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Busca e grava os uplinks novos de uma aplicação.
     *
     * @return array contagem por resultado (saved, duplicate, unmapped, ...) ou
     *               ['error' => mensagem] / ['skipped' => true] se outra busca
     *               da mesma aplicação já está em andamento
     */
    public function syncApplication(array $app)
    {
        // Duas páginas abertas ao mesmo tempo não buscam a mesma aplicação em dobro
        $lockName = 'ifscentral_ttn_' . (int)$app['id'];
        $lock = $this->conn->prepare("SELECT GET_LOCK(?, 0)");
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return ['skipped' => true];
        }

        $counts = ['saved' => 0, 'duplicate' => 0, 'unmapped' => 0, 'no_payload' => 0, 'invalid' => 0, 'failed' => 0];
        try {
            $this->conn->prepare("UPDATE ttn_applications SET last_sync_at = NOW() WHERE id = ?")->execute([$app['id']]);

            $cursor = $app['last_received_at'];
            $cursorTime = TtnClient::parseTime($cursor);

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $after = $cursor;
                if ($page === 0 && $cursorTime !== null) {
                    $after = gmdate('Y-m-d\\TH:i:s\\Z', (int)floor($cursorTime) - self::OVERLAP_SECONDS);
                }
                $uplinks = TtnClient::fetchStoredUplinks(
                    $app['cluster'], $app['application_id'], $app['api_key'], $after, self::PAGE_SIZE
                );

                foreach ($uplinks as $uplink) {
                    $counts[$this->ingestUplink($app['project_id'], $uplink)]++;

                    $time = TtnClient::parseTime($uplink['received_at'] ?? null);
                    if ($time !== null && ($cursorTime === null || $time > $cursorTime)) {
                        $cursorTime = $time;
                        $cursor = $uplink['received_at'];
                    }
                }

                $this->conn->prepare("UPDATE ttn_applications SET last_received_at = ?, last_error = NULL WHERE id = ?")
                    ->execute([$cursor, $app['id']]);

                if (count($uplinks) < self::PAGE_SIZE) {
                    break;
                }
            }
            return $counts;

        } catch (\RuntimeException $e) {
            $this->conn->prepare("UPDATE ttn_applications SET last_error = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 255), $app['id']]);
            return ['error' => $e->getMessage()];

        } finally {
            $this->conn->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
        }
    }

    /**
     * Grava um uplink do TTN no dispositivo do projeto ligado ao end device.
     *
     * @return string saved | duplicate | unmapped | no_payload | invalid | failed
     */
    public function ingestUplink($project_id, array $uplink, $source = 'ttn')
    {
        $ttnDeviceId = $uplink['end_device_ids']['device_id'] ?? null;
        $receivedAt = $uplink['received_at'] ?? ($uplink['uplink_message']['received_at'] ?? null);
        $receivedTime = TtnClient::parseTime($receivedAt);
        if (!is_string($ttnDeviceId) || $receivedTime === null) {
            return 'invalid';
        }

        // Sem payload formatter no TTN, o uplink chega só com frm_payload (bytes)
        $payload = $uplink['uplink_message']['decoded_payload'] ?? null;
        if (!is_array($payload) || $payload === []) {
            return 'no_payload';
        }

        $stmt = $this->conn->prepare("
            SELECT id, api_key FROM devices
            WHERE project_id = ? AND ttn_device_id = ? AND deletedAt IS NULL
            LIMIT 1");
        $stmt->execute([$project_id, $ttnDeviceId]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$device) {
            return 'unmapped';
        }

        $handler = new PayloadHandler($this->conn);
        $validation = $handler->validatePayload($payload, $device['id']);
        if (!$validation['valid']) {
            return 'invalid';
        }

        $result = $handler->savePayload(
            $device['id'], $device['api_key'], $payload, $source, $receivedTime, self::externalId($receivedAt)
        );
        if (!$result['success']) {
            return 'failed';
        }
        return empty($result['duplicate']) ? 'saved' : 'duplicate';
    }

    /**
     * Identificador do uplink, igual em todos os caminhos (Storage, MQTT e
     * webhook): o horário de recepção no TTN, único por end device.
     */
    public static function externalId($received_at)
    {
        return 'ttn:' . $received_at;
    }
}
?>

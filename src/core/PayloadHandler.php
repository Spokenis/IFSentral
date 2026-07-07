<?php
/**
 * PayloadHandler.php - Lógica compartilhada para salvar payloads
 * Usado tanto por webhooks HTTP quanto por MQTT subscriber
 */

namespace App\Core;

class PayloadHandler
{
    private $conn;

    public function __construct($pdo_connection)
    {
        $this->conn = $pdo_connection;
    }

    /**
     * Salva um payload de dispositivo no banco
     * 
     * @param int $device_id ID do dispositivo
     * @param string $api_key Chave de API do dispositivo
     * @param array|object $payload_data Dados do payload
     * @param string $source Fonte do payload ('http', 'mqtt', 'ttn')
     * 
     * @return array ['success' => bool, 'message' => string, 'id' => int|null]
     */
    public function savePayload($device_id, $api_key, $payload_data, $source = 'http')
    {
        try {
            if (!is_numeric($device_id)) {
                return [
                    'success' => false,
                    'message' => 'device_id inválido',
                    'id' => null
                ];
            }

            $device_id = intval($device_id);

            // Verifica autenticação
            $authSql = "SELECT id, project_id FROM devices WHERE id = ? AND api_key = ?";
            $authStmt = $this->conn->prepare($authSql);
            $authStmt->execute([$device_id, $api_key]);

            if ($authStmt->rowCount() == 0) {
                return [
                    'success' => false,
                    'message' => 'API Key ou Device ID inválidos',
                    'id' => null
                ];
            }

            $device = $authStmt->fetch(\PDO::FETCH_ASSOC);

            // Converte payload para JSON se for objeto
            if (is_object($payload_data)) {
                $payload_data = (array) $payload_data;
            }

            $payload_string = json_encode($payload_data);

            // Valida JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'message' => 'Payload JSON inválido: ' . json_last_error_msg(),
                    'id' => null
                ];
            }

            // Salva no banco
            $sql = "INSERT INTO device_payloads (device_id, payload, source) VALUES (?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $device_id,
                $payload_string,
                $source
            ]);

            $last_id = $this->conn->lastInsertId();

            return [
                'success' => true,
                'message' => 'Payload salvo com sucesso',
                'id' => intval($last_id),
                'device_id' => $device_id,
                'project_id' => $device['project_id'],
                'source' => $source
            ];

        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => 'Erro ao salvar payload: ' . $e->getMessage(),
                'id' => null
            ];
        }
    }

    /**
     * Valida um payload antes de salvar
     * Pode ser estendido com regras customizadas por tipo de dispositivo
     * 
     * @param array $payload_data
     * @param int $device_id
     * 
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validatePayload($payload_data, $device_id = null)
    {
        $errors = [];

        // Validações básicas
        if (!is_array($payload_data) && !is_object($payload_data)) {
            $errors[] = 'Payload deve ser um array ou objeto';
        }

        if (empty($payload_data)) {
            $errors[] = 'Payload não pode estar vazio';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
?>

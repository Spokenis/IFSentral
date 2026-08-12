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
     * * @param int $device_id ID do dispositivo
     * @param string $api_key Chave de API do dispositivo
     * @param array|object $payload_data Dados do payload
     * @param string $source Fonte do payload ('http', 'mqtt', 'ttn')
     * * @return array ['success' => bool, 'message' => string, 'id' => int|null]
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
     * Permite aninhamentos, mas impõe limites de profundidade e quantidade total de chaves
     * * @param array|object $payload_data
     * @param int|null $device_id
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validatePayload($payload_data, $device_id = null)
    {
        $errors = [];

        if (!is_array($payload_data) && !is_object($payload_data)) {
            $errors[] = 'Payload deve ser um array ou objeto JSON válido.';
            return ['valid' => false, 'errors' => $errors];
        }

        $payload_array = (array) $payload_data;

        if (empty($payload_array)) {
            $errors[] = 'Payload não pode estar vazio.';
            return ['valid' => false, 'errors' => $errors];
        }

        // Contador de chaves por referência para a validação recursiva
        $keyCount = 0;
        $structureError = $this->checkStructure($payload_array, $keyCount);

        if ($structureError) {
            $errors[] = $structureError;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validação recursiva da estrutura do JSON
     */
    private function checkStructure($data, &$keyCount, $depth = 0) 
    {
        // Limite de 5 níveis de aninhamento (evita complexidade excessiva no parsing futuro)
        if ($depth > 5) {
            return "O payload excede a profundidade máxima de aninhamento permitida (5 níveis).";
        }

        // Limite de 50 chaves/nós no total do JSON
        if ($keyCount > 50) {
            return "O payload contém um número excessivo de chaves (máximo 50 no total).";
        }

        foreach ($data as $key => $value) {
            $keyCount++;
            
            if (is_string($key) && strlen($key) > 40) {
                return "A chave '$key' excede o limite de 40 caracteres.";
            }
            
            if (is_string($value) && strlen($value) > 255) {
                return "O valor associado à chave '$key' é muito longo (máximo de 255 caracteres).";
            }
            
            if (is_array($value) || is_object($value)) {
                $error = $this->checkStructure((array)$value, $keyCount, $depth + 1);
                if ($error) return $error;
            }
        }
        
        return null;
    }
}
?>
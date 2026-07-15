<?php
/**
 * RateLimiter.php - Classe para gerenciar rate limiting
 * Usa configurações do banco de dados, editáveis pelo admin
 */

namespace App\Core;

class RateLimiter
{
    private $conn;
    private $settings;

    public function __construct($pdo_connection)
    {
        $this->conn = $pdo_connection;
        $this->loadSettings();
    }

    /**
     * Carrega configurações globais de rate limiting
     */
    private function loadSettings()
    {
        try {
            $sql = "SELECT setting_key, setting_value FROM api_settings WHERE is_editable = 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            $this->settings = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Exception $e) {
            // Fallback para valores padrão se banco não tiver settings
            $this->settings = $this->getDefaultSettings();
        }
    }

    /**
     * Retorna valores padrão se configuração não existir
     */
    private function getDefaultSettings()
    {
        return [
            'RATE_LIMIT_ENABLED' => '1',
            'RATE_LIMIT_REQUESTS_PER_MINUTE' => '60',
            'RATE_LIMIT_WINDOW_MINUTES' => '1',
            'RATE_LIMIT_SOFT_LIMIT_PERCENT' => '80',
            'LOG_RATE_LIMIT_VIOLATIONS' => '1',
        ];
    }

    /**
     * Obtém o limite de requisições para um dispositivo
     * Considera override por device
     */
    private function getDeviceLimit($device_id)
    {
        try {
            // Primeiro tenta buscar limite customizado do dispositivo
            $sql = "SELECT custom_requests_per_minute FROM device_rate_limits WHERE device_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$device_id]);
            
            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row['custom_requests_per_minute'] !== null) {
                    return intval($row['custom_requests_per_minute']);
                }
            }
        } catch (\Exception $e) {
            // Ignora erro e usa limit global
        }

        // Usa limite global
        return intval($this->settings['RATE_LIMIT_REQUESTS_PER_MINUTE'] ?? 60);
    }

    /**
     * Verifica se dispositivo está dentro do rate limit
     * Retorna: ['allowed' => bool, 'requests' => int, 'limit' => int, 'remaining' => int]
     */
    public function checkLimit($device_id, $source = 'http', $ip_address = null)
    {
        // Se rate limit está desabilitado globalmente
        if ($this->settings['RATE_LIMIT_ENABLED'] != 1) {
            return [
                'allowed' => true,
                'requests' => 0,
                'limit' => 0,
                'remaining' => 999,
                'reason' => 'Rate limit desabilitado'
            ];
        }

        try {
            $windowMinutes = intval($this->settings['RATE_LIMIT_WINDOW_MINUTES'] ?? 1);
            $limit = $this->getDeviceLimit($device_id);
            
            // Conta requisições nos últimos X minutos
            $sql = "SELECT COUNT(*) as count FROM device_payloads 
                    WHERE device_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$device_id, $windowMinutes]);
            
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $requestCount = intval($row['count']);

            $allowed = $requestCount < $limit;
            $remaining = max(0, $limit - $requestCount);
            
            // Log se violou o limite
            if (!$allowed && $this->settings['LOG_RATE_LIMIT_VIOLATIONS'] == 1) {
                $this->logViolation($device_id, $requestCount, $limit, $source, $ip_address);
            }

            // Log se atingiu soft limit (aviso)
            if ($this->settings['RATE_LIMIT_SOFT_LIMIT_PERCENT']) {
                $softLimit = ($limit * intval($this->settings['RATE_LIMIT_SOFT_LIMIT_PERCENT'])) / 100;
                if ($requestCount >= $softLimit && $requestCount < $limit) {
                    $this->logWarning($device_id, $requestCount, $limit, $source);
                }
            }

            return [
                'allowed' => $allowed,
                'requests' => $requestCount,
                'limit' => $limit,
                'remaining' => $remaining,
                'reason' => $allowed ? 
                    'OK' : 
                    'Rate limit excedido: ' . $requestCount . '/' . $limit . ' requisições por minuto'
            ];

        } catch (\Exception $e) {
            // FALHA FECHADA: Bloqueia em caso de erro (segurança > disponibilidade)
            \error_log('RateLimiter Error for device ' . $device_id . ': ' . $e->getMessage());
            return [
                'allowed' => false,  // ✅ FALHA FECHADA - Bloqueia para proteger
                'requests' => 0,
                'limit' => 0,
                'remaining' => 0,
                'reason' => 'Erro ao verificar rate limit. Requisição bloqueada. (Erro interno: ' . $e->getMessage() . ')'
            ];
        }
    }

    /**
     * Registra violação de rate limit
     */
    private function logViolation($device_id, $requests, $limit, $source, $ip_address)
    {
        try {
            $sql = "INSERT INTO rate_limit_violations (device_id, endpoint, requests_in_window, limit_value, source, ip_address) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$device_id, 'payload_endpoint', $requests, $limit, $source, $ip_address]);
        } catch (\Exception $e) {
            // Não quebra a requisição se falhar log
        }
    }

    /**
     * Registra aviso (soft limit)
     */
    private function logWarning($device_id, $requests, $limit, $source)
    {
        try {
            $logFile = __DIR__ . '/../../logs/rate_limit_warnings.log';
            if (!is_dir(dirname($logFile))) {
                mkdir(dirname($logFile), 0755, true);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $message = "[$timestamp] AVISO: Device $device_id atingiu $requests/$limit requisições ($source)\n";
            file_put_contents($logFile, $message, FILE_APPEND);
        } catch (\Exception $e) {
            // Ignora erro silenciosamente
        }
    }

    /**
     * Obtém estatísticas de rate limit para um dispositivo
     */
    public function getStats($device_id)
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_violations,
                        AVG(requests_in_window) as avg_requests,
                        MAX(requests_in_window) as max_requests,
                        MAX(created_at) as last_violation
                    FROM rate_limit_violations 
                    WHERE device_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$device_id]);
            
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtém todas as configurações atuais
     */
    public function getAllSettings()
    {
        return $this->settings;
    }

    /**
     * Atualiza uma configuração (apenas se for admin)
     */
    public function updateSetting($key, $value)
    {
        try {
            $sql = "UPDATE api_settings SET setting_value = ? WHERE setting_key = ? AND is_editable = 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$value, $key]);
            
            if ($stmt->rowCount() > 0) {
                // Recarrega as settings
                $this->loadSettings();
                return ['success' => true, 'message' => 'Configuração atualizada'];
            } else {
                return ['success' => false, 'message' => 'Configuração não encontrada ou não é editável'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Erro: ' . $e->getMessage()];
        }
    }
}
?>

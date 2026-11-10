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
            
            // Conta as leituras que CHEGARAM nos últimos X minutos (inserido_em),
            // e não pelo instante da medição: leituras atrasadas enviadas com
            // "timestamp" antigo também contam. Banco sem a migração
            // 2026-11-09_inserido_em.sql: conta pelo created_at, como antes.
            try {
                $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM device_payloads
                        WHERE device_id = ? AND inserido_em > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
                $stmt->execute([$device_id, $windowMinutes]);
            } catch (\PDOException $e) {
                $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM device_payloads
                        WHERE device_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
                $stmt->execute([$device_id, $windowMinutes]);
            }
            
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
     * Verifica se um identificador (e-mail) e/ou IP excederam o limite de
     * tentativas de login mal-sucedidas na janela configurada.
     * Retorna: ['allowed' => bool, 'attempts' => int, 'max_attempts' => int]
     */
    public function checkLoginAttempts($identifier, $ip_address)
    {
        if (($this->settings['LOGIN_RATE_LIMIT_ENABLED'] ?? '1') != 1) {
            return ['allowed' => true, 'attempts' => 0, 'max_attempts' => 0, 'motivo' => null];
        }

        $cfg = $this->loginConfig();
        $maxAttempts = $cfg['por_conta'];
        $windowMinutes = $cfg['janela'];
        // Limite por IP, separado e configurável pelo admin (Admin > Rate
        // Limiting > Proteção do login). Num laboratório ou na rede do câmpus,
        // muitos usuários saem pelo mesmo IP (NAT): o limite por IP precisa ser
        // bem maior que o por conta, pode ser desligado (0) e as redes do
        // câmpus podem ficar isentas. O limite por conta continua valendo.
        $maxAttemptsPerIp = $cfg['por_ip'];
        if ($maxAttemptsPerIp > 0 && self::ipNaLista((string)$ip_address, $cfg['ips_isentos'])) {
            $maxAttemptsPerIp = 0;
        }

        try {
            $sql = "SELECT
                        COALESCE(SUM(identifier = ?), 0) AS by_identifier,
                        COALESCE(SUM(ip_address = ?), 0) AS by_ip
                    FROM login_attempts
                    WHERE success = 0
                      AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                      AND (identifier = ? OR ip_address = ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$identifier, $ip_address, $windowMinutes, $identifier, $ip_address]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $attempts = intval($row['by_identifier']);
            $attemptsByIp = intval($row['by_ip']);

            $motivo = null;
            if ($attempts >= $maxAttempts) {
                $motivo = 'conta';
            } elseif ($maxAttemptsPerIp > 0 && $attemptsByIp >= $maxAttemptsPerIp) {
                $motivo = 'ip';
            }
            return [
                'allowed' => $motivo === null,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'motivo' => $motivo,
                'janela_minutos' => $windowMinutes,
            ];
        } catch (\Exception $e) {
            // Falha ABERTA aqui de propósito: diferente do checkLimit() de dispositivos,
            // isto protege o login de usuários humanos. Se a tabela login_attempts ainda
            // não existir (setup-security-tables.php não executado), bloquear todo login
            // por erro de infraestrutura seria pior que a ausência temporária da proteção.
            \error_log('RateLimiter::checkLoginAttempts error: ' . $e->getMessage());
            return ['allowed' => true, 'attempts' => 0, 'max_attempts' => 0, 'motivo' => null];
        }
    }

    /**
     * Configuração da proteção do login, com os padrões quando a chave não
     * existe em api_settings (instalações anteriores a 10/11/2026)
     */
    public function loginConfig(): array
    {
        $porConta = intval($this->settings['LOGIN_RATE_LIMIT_MAX_ATTEMPTS'] ?? 5);
        return [
            'ativo' => ($this->settings['LOGIN_RATE_LIMIT_ENABLED'] ?? '1') == 1,
            'por_conta' => max(1, $porConta),
            'janela' => max(1, intval($this->settings['LOGIN_RATE_LIMIT_WINDOW_MINUTES'] ?? 15)),
            // Antes era fixo em 4x o limite por conta (20 com o padrão de 5)
            'por_ip' => max(0, intval($this->settings['LOGIN_RATE_LIMIT_MAX_PER_IP'] ?? ($porConta * 4))),
            'ips_isentos' => (string)($this->settings['LOGIN_RATE_LIMIT_IP_ISENTOS'] ?? ''),
        ];
    }

    /**
     * O IP está na lista? Aceita IPs e redes CIDR (IPv4 e IPv6) separados por
     * vírgula, espaço ou quebra de linha. Ex.: "10.50.0.0/16, 192.168.1.20"
     */
    public static function ipNaLista(string $ip, string $lista): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false || trim($lista) === '') {
            return false;
        }
        foreach (preg_split('/[\s,;]+/', trim($lista)) as $item) {
            if ($item === '') continue;
            [$rede, $bits] = array_pad(explode('/', $item, 2), 2, null);
            $redeBin = @inet_pton($rede);
            if ($redeBin === false || strlen($redeBin) !== strlen($bin)) continue;
            $total = strlen($bin) * 8;
            $bits = $bits === null ? $total : (int)$bits;
            if ($bits < 0 || $bits > $total) continue;
            $bytes = intdiv($bits, 8);
            $resto = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($redeBin, 0, $bytes)) continue;
            if ($resto === 0) return true;
            $mascara = (0xFF << (8 - $resto)) & 0xFF;
            if ((ord($bin[$bytes]) & $mascara) === (ord($redeBin[$bytes]) & $mascara)) return true;
        }
        return false;
    }

    /**
     * Valida e normaliza a lista de IPs/redes isentos digitada pelo admin.
     * Devolve [lista normalizada, itens inválidos].
     */
    public static function normalizarListaIps(string $texto): array
    {
        $ok = []; $invalidos = [];
        foreach (preg_split('/[\s,;]+/', trim($texto)) as $item) {
            if ($item === '') continue;
            [$rede, $bits] = array_pad(explode('/', $item, 2), 2, null);
            $bin = @inet_pton($rede);
            $max = $bin === false ? 0 : strlen($bin) * 8;
            if ($bin === false || ($bits !== null && (!ctype_digit($bits) || (int)$bits > $max))) {
                $invalidos[] = $item;
                continue;
            }
            $ok[] = $item;
        }
        return [implode(', ', array_unique($ok)), $invalidos];
    }

    /**
     * Contas e IPs bloqueados agora (para a tela do admin)
     */
    public function bloqueiosLogin(): array
    {
        $cfg = $this->loginConfig();
        $res = ['contas' => [], 'ips' => []];
        try {
            $st = $this->conn->prepare(
                "SELECT identifier AS alvo, COUNT(*) AS falhas, MAX(created_at) AS ultima FROM login_attempts
                 WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 GROUP BY identifier HAVING falhas >= ? ORDER BY ultima DESC LIMIT 50");
            $st->execute([$cfg['janela'], $cfg['por_conta']]);
            $res['contas'] = $st->fetchAll(\PDO::FETCH_ASSOC);
            if ($cfg['por_ip'] > 0) {
                $st = $this->conn->prepare(
                    "SELECT ip_address AS alvo, COUNT(*) AS falhas, MAX(created_at) AS ultima FROM login_attempts
                     WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE) AND ip_address IS NOT NULL
                     GROUP BY ip_address HAVING falhas >= ? ORDER BY ultima DESC LIMIT 50");
                $st->execute([$cfg['janela'], $cfg['por_ip']]);
                $res['ips'] = array_values(array_filter($st->fetchAll(\PDO::FETCH_ASSOC),
                    fn($r) => !self::ipNaLista((string)$r['alvo'], $cfg['ips_isentos'])));
            }
        } catch (\Exception $e) {
            \error_log('RateLimiter::bloqueiosLogin error: ' . $e->getMessage());
        }
        return $res;
    }

    /**
     * Desbloqueia: apaga as falhas recentes de uma conta, de um IP ou de todos.
     * $tipo: 'conta' | 'ip' | 'todos'. Devolve quantas falhas foram apagadas.
     */
    public function desbloquearLogin(string $tipo, string $alvo = ''): int
    {
        $sql = "DELETE FROM login_attempts WHERE success = 0";
        $p = [];
        if ($tipo === 'conta') { $sql .= " AND identifier = ?"; $p[] = $alvo; }
        elseif ($tipo === 'ip') { $sql .= " AND ip_address = ?"; $p[] = $alvo; }
        elseif ($tipo !== 'todos') { return 0; }
        $st = $this->conn->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    /**
     * Grava uma configuração criando a chave se ainda não existir (as chaves
     * novas da proteção do login não estão em instalações antigas)
     */
    public function salvarConfiguracao(string $key, string $value, string $descricao = ''): bool
    {
        try {
            $st = $this->conn->prepare(
                "INSERT INTO api_settings (setting_key, setting_value, description, is_editable) VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $st->execute([$key, $value, $descricao]);
            $this->loadSettings();
            return true;
        } catch (\Exception $e) {
            \error_log('RateLimiter::salvarConfiguracao error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Registra uma tentativa de login (sucesso ou falha) para fins de rate limiting
     */
    public function recordLoginAttempt($identifier, $ip_address, $success)
    {
        try {
            $sql = "INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$identifier, $ip_address, $success ? 1 : 0]);
        } catch (\Exception $e) {
            // Não quebra o fluxo de login se o registro falhar
        }
    }

    /**
     * Registra violação de rate limit
     */
    private function logViolation($device_id, $requests, $limit, $source, $ip_address)
    {
        try {
            // Um registro por dispositivo e origem a cada minuto. Antes era uma
            // linha por requisição recusada: no teste com ESP32 de 09/11/2026,
            // um dispositivo enviando ~300 msg/s gerou 8.427 linhas em 32 s —
            // gravações no banco justamente quando ele já está sob pressão.
            $recente = $this->conn->prepare(
                "SELECT 1 FROM rate_limit_violations
                 WHERE device_id = ? AND source = ? AND created_at > NOW() - INTERVAL 1 MINUTE LIMIT 1"
            );
            $recente->execute([$device_id, $source]);
            if ($recente->fetchColumn()) {
                return;
            }
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

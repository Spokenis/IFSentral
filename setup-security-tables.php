#!/usr/bin/env php
<?php
/**
 * setup-security-tables.php - Configura tabelas de segurança
 * Execute uma única vez: php setup-security-tables.php
 */

define('ROOT_DIR', __DIR__);

echo "\n" . str_repeat('=', 80) . "\n";
echo "🔐 SETUP DE SEGURANÇA - IFSentral\n";
echo str_repeat('=', 80) . "\n\n";

// Carrega conexão
try {
    require ROOT_DIR . '/src/config/config.php';
    require ROOT_DIR . '/src/config/db.php';
    echo "✅ Conexão com banco de dados OK\n\n";
} catch (Exception $e) {
    echo "❌ Erro ao conectar: " . $e->getMessage() . "\n";
    exit(1);
}

// SQL para criar tabelas de segurança
$sqls = [
    "api_settings" => "
        CREATE TABLE IF NOT EXISTS api_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value VARCHAR(500),
            description TEXT,
            is_editable BOOLEAN DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
    "rate_limit_violations" => "
        CREATE TABLE IF NOT EXISTS rate_limit_violations (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            device_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED,
            endpoint VARCHAR(100),
            requests_in_window INT,
            limit_value INT,
            source VARCHAR(20),
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_device_time (device_id, created_at DESC),
            INDEX idx_violations_recent (created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
    "device_rate_limits" => "
        CREATE TABLE IF NOT EXISTS device_rate_limits (
            device_id INT UNSIGNED PRIMARY KEY,
            custom_requests_per_minute INT DEFAULT NULL,
            enabled BOOLEAN DEFAULT 1,
            reason TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
    "mqtt_credentials" => "
        CREATE TABLE IF NOT EXISTS mqtt_credentials (
            id INT PRIMARY KEY AUTO_INCREMENT,
            device_id INT UNSIGNED UNIQUE NOT NULL,
            mqtt_username VARCHAR(100) UNIQUE NOT NULL,
            mqtt_password_hash VARCHAR(255) NOT NULL,
            enabled BOOLEAN DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
            INDEX idx_username (mqtt_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
];

// Executar SQL
echo "Criando tabelas de segurança...\n\n";

foreach ($sqls as $table_name => $sql) {
    try {
        $conn->exec($sql);
        echo "✅ Tabela `$table_name` criada/verificada\n";
    } catch (Exception $e) {
        echo "❌ Erro ao criar `$table_name`: " . $e->getMessage() . "\n";
    }
}

// Inserir settings padrão
echo "\nConfigurando rate limiting padrão...\n";

$default_settings = [
    'RATE_LIMIT_ENABLED' => '1',
    'RATE_LIMIT_REQUESTS_PER_MINUTE' => '60',
    'RATE_LIMIT_WINDOW_MINUTES' => '1',
    'MQTT_AUTH_ENABLED' => '1',
    'MQTT_ACL_ENABLED' => '1',
    'RATE_LIMIT_SOFT_LIMIT_PERCENT' => '80',
    'LOG_RATE_LIMIT_VIOLATIONS' => '1',
];

try {
    $sql_check = "SELECT COUNT(*) as cnt FROM api_settings WHERE setting_key = ?";
    $sql_insert = "INSERT INTO api_settings (setting_key, setting_value, description, is_editable) VALUES (?, ?, ?, 1)";
    
    foreach ($default_settings as $key => $value) {
        $stmt = $conn->prepare($sql_check);
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row['cnt'] == 0) {
            $stmt = $conn->prepare($sql_insert);
            $stmt->execute([$key, $value, "Configuração de: $key"]);
            echo "✅ Setting `$key` = `$value`\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Erro ao inserir settings: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "✅ SETUP DE SEGURANÇA COMPLETO!\n";
echo str_repeat('=', 80) . "\n";
echo "\nPróximos passos:\n";
echo "  1. Execute: php system-check.php\n";
echo "  2. Inicie MQTT: php src/mqtt/mqtt_subscriber.php\n";
echo "  3. Configure cron: */5 * * * * php src/mqtt/mqtt_health_check.php\n\n";
?>

-- MIGRATION_MQTT_SECURITY.sql
-- Adiciona segurança ao MQTT: autenticação e ACLs
-- Também adiciona configurações globais de rate limiting

-- Tabela: Configurações Globais (editável por admin)
CREATE TABLE IF NOT EXISTS api_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value VARCHAR(500),
    description TEXT,
    is_editable BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insere configurações padrão de rate limiting
INSERT INTO api_settings (setting_key, setting_value, description, is_editable) VALUES
('RATE_LIMIT_ENABLED', '1', 'Habilitar rate limiting (1=sim, 0=não)', 1),
('RATE_LIMIT_REQUESTS_PER_MINUTE', '60', 'Máximo de requisições HTTP por minuto por dispositivo', 1),
('RATE_LIMIT_WINDOW_MINUTES', '1', 'Janela de tempo para contagem (em minutos)', 1),
('MQTT_AUTH_ENABLED', '1', 'Habilitar autenticação MQTT (1=sim, 0=não)', 1),
('MQTT_ACL_ENABLED', '1', 'Habilitar ACLs no MQTT (cada device só publica no seu tópico)', 1),
('RATE_LIMIT_SOFT_LIMIT_PERCENT', '80', 'Percentual do limite para começar a alertar (0-100)', 1),
('LOG_RATE_LIMIT_VIOLATIONS', '1', 'Registrar violações de rate limit nos logs', 1);

-- Tabela: Auditoria de Rate Limiting
CREATE TABLE IF NOT EXISTS rate_limit_violations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED,
    endpoint VARCHAR(100),
    requests_in_window INT,
    limit_value INT,
    source VARCHAR(20),  -- 'http', 'mqtt'
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_device_time (device_id, created_at DESC),
    INDEX idx_violations_recent (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabela: Rate Limit por Dispositivo (permite override)
CREATE TABLE IF NOT EXISTS device_rate_limits (
    device_id INT UNSIGNED PRIMARY KEY,
    custom_requests_per_minute INT DEFAULT NULL,  -- NULL = usar global
    enabled BOOLEAN DEFAULT 1,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabela: Credenciais MQTT (username/password por dispositivo)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabela: ACL MQTT (controle de tópicos)
CREATE TABLE IF NOT EXISTS mqtt_acl (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT UNSIGNED NOT NULL,
    allow_subscribe BOOLEAN DEFAULT 0,  # Pode subscrever
    allow_publish BOOLEAN DEFAULT 1,    # Pode publicar
    topic_filter VARCHAR(255) NOT NULL,  # mqtt/projects/+/devices/+ por padrão
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    UNIQUE KEY unique_device_topic (device_id, topic_filter),
    INDEX idx_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Índices para performance de rate limiting
CREATE INDEX idx_rate_limit_check ON device_payloads(device_id, created_at DESC);

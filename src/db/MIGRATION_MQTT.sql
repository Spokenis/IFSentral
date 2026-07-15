-- MIGRATION_MQTT.sql
-- Adiciona suporte a MQTT na tabela device_payloads

-- Adiciona coluna 'source' para rastrear origem do payload
-- Valores: 'http', 'mqtt', 'ttn', 'app'
ALTER TABLE device_payloads 
ADD COLUMN source VARCHAR(20) DEFAULT 'http' AFTER payload;

-- Adiciona índice para melhorar queries por source
CREATE INDEX idx_device_payloads_source ON device_payloads(source, created_at);

-- Adiciona índice temporal (importante para IoT)
CREATE INDEX idx_device_payloads_time ON device_payloads(device_id, created_at DESC);

-- Cria tabela para rastrear status de conexão MQTT dos dispositivos
CREATE TABLE IF NOT EXISTS device_mqtt_status (
    device_id INT UNSIGNED NOT NULL,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_online BOOLEAN DEFAULT 0,
    signal_strength INT DEFAULT NULL,  -- dBm
    PRIMARY KEY (device_id),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

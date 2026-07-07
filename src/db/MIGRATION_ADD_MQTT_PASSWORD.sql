-- Adiciona coluna para armazenar senha MQTT no banco de dados
-- Isso resolve o problema de permissões de arquivo

ALTER TABLE mqtt_credentials 
ADD COLUMN mqtt_password VARCHAR(255) NULL AFTER mqtt_username;

-- Índice para busca rápida
CREATE INDEX idx_mqtt_password ON mqtt_credentials(device_id, mqtt_password);

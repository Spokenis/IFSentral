#!/usr/bin/env php
<?php
/**
 * migrate_mqtt_usernames.php
 * 
 * Migra usernames MQTT de device_{id} para mqdev_{api_key_hash}
 * 
 * ANTES: device_2, device_3, device_5, device_6, device_7
 * DEPOIS: mqdev_a1b2c3d4e5f6g7h8, mqdev_x9y8z7w6v5u4t3s2, etc
 * 
 * Uso: php src/mqtt/migrate_mqtt_usernames.php
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('CONFIG_DIR', ROOT_DIR . '/src/config');

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';

echo "=== Migração de Usernames MQTT ===\n\n";

try {
    // Busca todos os devices com credenciais MQTT no formato antigo
    $stmt = $conn->prepare("
        SELECT 
            mc.id as credential_id,
            mc.device_id,
            mc.mqtt_username,
            d.api_key,
            d.name as device_name
        FROM mqtt_credentials mc
        JOIN devices d ON mc.device_id = d.id
        WHERE mc.mqtt_username LIKE 'device_%'
        ORDER BY mc.device_id
    ");
    $stmt->execute();
    
    $devices = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    if (empty($devices)) {
        echo "✅ Nenhum device para migrar. Todos já estão no novo formato!\n";
        exit(0);
    }
    
    echo "📋 Encontrados " . count($devices) . " devices para migrar:\n\n";
    
    $migrated = 0;
    $errors = 0;
    
    foreach ($devices as $device) {
        $old_username = $device['mqtt_username'];
        $api_key = $device['api_key'];
        $key_hash = substr($api_key, 0, 16);
        $new_username = "mqdev_" . $key_hash;
        
        echo "Device #{$device['device_id']} ({$device['device_name']})\n";
        echo "  Antigo: $old_username\n";
        echo "  Novo:   $new_username\n";
        
        try {
            $updateStmt = $conn->prepare("
                UPDATE mqtt_credentials 
                SET mqtt_username = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$new_username, $device['credential_id']]);
            
            echo "  ✅ Migrado\n\n";
            $migrated++;
            
        } catch (\Exception $e) {
            echo "  ❌ Erro: " . $e->getMessage() . "\n\n";
            $errors++;
        }
    }
    
    echo "=====================================\n";
    echo "✅ Migrados: $migrated\n";
    
    if ($errors > 0) {
        echo "❌ Erros: $errors\n";
    }
    
    echo "\n⚠️  IMPORTANTE: Execute agora:\n";
    echo "   sudo php src/mqtt/sync_mosquitto.php\n\n";
    echo "Isso atualizará os arquivos do Mosquitto com os novos usernames.\n";
    
    exit(0);
    
} catch (\Exception $e) {
    echo "❌ Erro crítico: " . $e->getMessage() . "\n";
    exit(1);
}
?>

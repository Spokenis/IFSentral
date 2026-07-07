#!/usr/bin/env php
<?php
/**
 * generate_mqtt_credentials.php
 * Script para gerar credenciais MQTT para dispositivos
 * 
 * Uso: php src/mqtt/generate_mqtt_credentials.php [device_id]
 * Se device_id não for fornecido, gera para todos os dispositivos ativos
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('SRC_DIR', ROOT_DIR . '/src');
define('CONFIG_DIR', SRC_DIR . '/config');

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';

echo "=== Gerador de Credenciais MQTT ===\n\n";

// Função para gerar senha aleatória
function generateRandomPassword($length = 16) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $password;
}

// Função para fazer hash da senha (compatível com Mosquitto)
function hashMosquittoPassword($password) {
    // Mosquitto usa PBKDF2 com SHA256
    // Formato: $7$base64(salt)$base64(hash)
    // Para simplicidade, usamos password_hash do PHP e depois convertemos
    // Nota: Este é um método simplificado. Para produção, usar mosquitto_passwd CLI
    return password_hash($password, PASSWORD_BCRYPT);
}

try {
    // Determina qual(is) dispositivo(s) processar
    $device_ids = [];
    
    if (isset($argv[1])) {
        // Gera para dispositivo específico
        $device_id = intval($argv[1]);
        
        // Verifica se dispositivo existe
        $checkStmt = $conn->prepare("SELECT id, name FROM devices WHERE id = ?");
        $checkStmt->execute([$device_id]);
        
        if ($checkStmt->rowCount() === 0) {
            echo "❌ Erro: Dispositivo ID $device_id não encontrado\n";
            exit(1);
        }
        
        $device = $checkStmt->fetch(\PDO::FETCH_ASSOC);
        $device_ids = [$device_id];
        
        echo "📱 Gerando credencial para: Device #$device_id ({$device['name']})\n\n";
    } else {
        // Gera para todos os dispositivos
        $stmt = $conn->prepare("SELECT id, name FROM devices ORDER BY id");
        $stmt->execute();
        
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $device_ids[] = $row['id'];
        }
        
        echo "📱 Gerando credenciais para " . count($device_ids) . " dispositivos...\n\n";
    }
    
    $generated = 0;
    $updated = 0;
    
    foreach ($device_ids as $device_id) {
        // Gera username e password
        $mqtt_username = "device_" . $device_id;
        $mqtt_password = generateRandomPassword(20);
        $password_hash = hashMosquittoPassword($mqtt_password);
        
        // Verifica se já existe
        $existStmt = $conn->prepare("SELECT id FROM mqtt_credentials WHERE device_id = ?");
        $existStmt->execute([$device_id]);
        
        if ($existStmt->rowCount() > 0) {
            // Atualiza
            $updateStmt = $conn->prepare(
                "UPDATE mqtt_credentials SET mqtt_password_hash = ?, updated_at = NOW() WHERE device_id = ?"
            );
            $updateStmt->execute([$password_hash, $device_id]);
            $updated++;
            echo "🔄 Atualizado: Device #$device_id → $mqtt_username\n";
        } else {
            // Insere novo
            $insertStmt = $conn->prepare(
                "INSERT INTO mqtt_credentials (device_id, mqtt_username, mqtt_password_hash, enabled) VALUES (?, ?, ?, 1)"
            );
            $insertStmt->execute([$device_id, $mqtt_username, $password_hash]);
            $generated++;
            echo "✅ Criado: Device #$device_id → $mqtt_username\n";
        }
        
        // Salva password em local seguro (arquivo com permissões restritas)
        $cred_file = ROOT_DIR . "/mqtt_credentials_backup.txt";
        $cred_entry = sprintf(
            "[Device #%d] Username: %s | Password: %s | Hash: %s\n",
            $device_id,
            $mqtt_username,
            $mqtt_password,
            substr($password_hash, 0, 20) . "...(hash)"
        );
        file_put_contents($cred_file, $cred_entry, FILE_APPEND);
        chmod($cred_file, 0600);  // Apenas owner pode ler
    }
    
    echo "\n=== Resumo ===\n";
    echo "✅ Criadas: $generated novas credenciais\n";
    echo "🔄 Atualizadas: $updated credenciais existentes\n";
    echo "\n📄 Credenciais salvas em: mqtt_credentials_backup.txt (chmod 0600)\n";
    echo "⚠️  Guarde este arquivo com segurança!\n\n";
    
    // Mostra informações de próximos passos
    echo "=== Próximos Passos ===\n";
    echo "1. Gerar arquivo ACL do Mosquitto:\n";
    echo "   php src/mqtt/generate_mosquitto_acl.php\n\n";
    echo "2. Gerar arquivo passwd do Mosquitto:\n";
    echo "   php src/mqtt/generate_mosquitto_passwd.php\n\n";
    echo "3. Configurar Mosquitto com autenticação em mosquitto.conf\n\n";
    
} catch (\Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
?>

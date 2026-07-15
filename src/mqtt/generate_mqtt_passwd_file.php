#!/usr/bin/env php
<?php
/**
 * generate_mqtt_passwd_file.php
 * Gera arquivo passwd do Mosquitto com hashes PBKDF2 SHA256
 * 
 * Alternativa em PHP (não precisa de Python)
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('CONFIG_DIR', ROOT_DIR . '/src/config');

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';

echo "=== Gerador de Arquivo Passwd do Mosquitto (PHP) ===\n\n";

/**
 * Gera hash PBKDF2 SHA256 compatível com Mosquitto
 * Formato: $7$iterations$salt_base64$hash_base64
 */
function generatePBKDF2Hash($password, $iterations = 101, $keylen = 64) {
    // Gera salt aleatório (12 bytes)
    $salt = random_bytes(12);
    
    // Calcula hash PBKDF2 com SHA512 (Mosquitto padrão)
    $hash = hash_pbkdf2('sha512', $password, $salt, $iterations, $keylen, true);
    
    // Codifica em base64
    $salt_b64 = base64_encode($salt);
    $hash_b64 = base64_encode($hash);
    
    // Formato Mosquitto 2.x: $7$iterations$salt_base64$hash_base64
    return sprintf('$7$%d$%s$%s', $iterations, $salt_b64, $hash_b64);
}

/**
 * Gera senha aleatória segura
 */
function generateRandomPassword($length = 20) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
    $password = '';
    $max = strlen($chars) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    
    return $password;
}

try {
    // Lê credenciais do banco
    $stmt = $conn->prepare("
        SELECT 
            mc.id,
            mc.device_id,
            mc.mqtt_username,
            d.name as device_name,
            d.project_id
        FROM mqtt_credentials mc
        JOIN devices d ON mc.device_id = d.id
        WHERE mc.enabled = 1
        ORDER BY mc.mqtt_username
    ");
    $stmt->execute();
    
    $credentials = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    if (empty($credentials)) {
        echo "❌ Nenhuma credencial MQTT encontrada. Execute generate_mqtt_credentials.php primeiro.\n";
        exit(1);
    }
    
    echo "🔐 Gerando arquivo passwd para " . count($credentials) . " usuários...\n\n";
    
    // Prepara conteúdo do arquivo passwd
    $passwd_content = "# Auto-gerado por generate_mqtt_passwd_file.php\n";
    $passwd_content .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n";
    $passwd_content .= "# Formato Mosquitto 2.x: username:$7$iterations$salt$hash\n\n";
    
    // Prepara backup de credenciais
    $backup_content = "# CREDENCIAIS MQTT - GUARDAR COM SEGURANÇA\n";
    $backup_content .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n";
    $backup_content .= "# NÃO COMPARTILHE ESTE ARQUIVO!\n\n";
    
    $generated = 0;
    
    foreach ($credentials as $cred) {
        $username = $cred['mqtt_username'];
        $device_id = $cred['device_id'];
        $device_name = $cred['device_name'];
        $project_id = $cred['project_id'];
        
        // Gera nova senha aleatória
        $password = generateRandomPassword(24);
        
        // Gera hash PBKDF2
        $hash = generatePBKDF2Hash($password);
        
        // Atualiza no banco
        $updateStmt = $conn->prepare(
            "UPDATE mqtt_credentials SET mqtt_password_hash = ?, updated_at = NOW() WHERE id = ?"
        );
        $updateStmt->execute([$hash, $cred['id']]);
        
        // Adiciona ao arquivo passwd
        $passwd_content .= "{$username}:{$hash}\n";
        
        // Adiciona ao backup
        $backup_content .= "═══════════════════════════════════════════════════════\n";
        $backup_content .= "Device: {$device_name} (ID: {$device_id})\n";
        $backup_content .= "Project ID: {$project_id}\n";
        $backup_content .= "─────────────────────────────────────────────────────\n";
        $backup_content .= "MQTT Username: {$username}\n";
        $backup_content .= "MQTT Password: {$password}\n";
        $backup_content .= "Password Hash: " . substr($hash, 0, 30) . "...\n";
        $backup_content .= "═══════════════════════════════════════════════════════\n\n";
        
        echo "✅ {$username} (Device: {$device_name})\n";
        $generated++;
    }
    
    // Adiciona usuário admin
    $admin_password = generateRandomPassword(32);
    $admin_hash = generatePBKDF2Hash($admin_password);
    $passwd_content .= "\n# Admin user\nadmin:$admin_hash\n";
    
    $backup_content .= "═══════════════════════════════════════════════════════\n";
    $backup_content .= "ADMIN ACCOUNT\n";
    $backup_content .= "─────────────────────────────────────────────────────\n";
    $backup_content .= "Username: admin\n";
    $backup_content .= "Password: {$admin_password}\n";
    $backup_content .= "═══════════════════════════════════════════════════════\n";
    
    echo "✅ admin (superuser)\n\n";
    
    // Tenta salvar em /etc/mosquitto/passwd
    $passwd_file = '/etc/mosquitto/passwd';
    $saved_to_etc = false;
    
    if (is_writable('/etc/mosquitto')) {
        if (file_put_contents($passwd_file, $passwd_content) !== false) {
            chmod($passwd_file, 0600);
            $saved_to_etc = true;
            echo "✅ Arquivo passwd salvo em: $passwd_file\n";
        }
    }
    
    if (!$saved_to_etc) {
        // Fallback para /tmp
        $passwd_file = '/tmp/mosquitto_passwd';
        file_put_contents($passwd_file, $passwd_content);
        chmod($passwd_file, 0600);
        echo "⚠️  Sem permissão em /etc/mosquitto. Salvo em: $passwd_file\n";
        echo "    Execute: sudo cp $passwd_file /etc/mosquitto/passwd\n";
        echo "             sudo chmod 600 /etc/mosquitto/passwd\n\n";
    }
    
    // Salva backup de senhas
    $backup_file = ROOT_DIR . '/mqtt_credentials_BACKUP_SEGURO.txt';
    file_put_contents($backup_file, $backup_content);
    chmod($backup_file, 0600);
    
    echo "💾 Backup de senhas salvo em: $backup_file\n";
    echo "   ⚠️  IMPORTANTE: Guarde este arquivo em local seguro!\n\n";
    
    // Estatísticas
    echo "=== Resumo ===\n";
    echo "✅ Total de usuários: " . ($generated + 1) . " (devices + admin)\n";
    echo "📄 Arquivo passwd: $passwd_file\n";
    echo "💾 Backup senhas: $backup_file\n\n";
    
    // Mostra preview
    echo "=== Preview do arquivo passwd ===\n";
    $lines = explode("\n", $passwd_content);
    foreach (array_slice($lines, 0, 8) as $line) {
        echo $line . "\n";
    }
    echo "... (" . (count($lines) - 8) . " linhas adicionais)\n\n";
    
    // Próximos passos
    echo "=== Próximos Passos ===\n";
    echo "1. Verificar arquivo:\n";
    if ($saved_to_etc) {
        echo "   sudo ls -la /etc/mosquitto/passwd\n\n";
    } else {
        echo "   sudo cp /tmp/mosquitto_passwd /etc/mosquitto/passwd\n";
        echo "   sudo chmod 600 /etc/mosquitto/passwd\n\n";
    }
    
    echo "2. Configurar Mosquitto:\n";
    echo "   Edite /etc/mosquitto/mosquitto.conf e adicione:\n";
    echo "   allow_anonymous false\n";
    echo "   password_file /etc/mosquitto/passwd\n";
    echo "   acl_file /etc/mosquitto/conf.d/acl.acl\n\n";
    
    echo "3. Recarregar Mosquitto:\n";
    echo "   sudo systemctl reload mosquitto\n\n";
    
    echo "4. Testar autenticação:\n";
    echo "   mosquitto_sub -h localhost -u device_2 -P 'SENHA' -t 'mqtt/projects/2/devices/2'\n\n";
    
    echo "✅ Concluído com sucesso!\n";
    
} catch (\Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
?>

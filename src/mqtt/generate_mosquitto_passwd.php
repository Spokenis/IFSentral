#!/usr/bin/env php
<?php
/**
 * generate_mosquitto_passwd.php
 * Gera arquivo passwd do Mosquitto com base em mqtt_credentials
 * 
 * Nota: Mosquitto espera hashes PBKDF2 SHA256 no formato específico
 * Este script usa mosquitto_passwd CLI se disponível
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('CONFIG_DIR', ROOT_DIR . '/src/config');
define('MOSQUITTO_CONF_DIR', '/etc/mosquitto/conf.d');

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';

echo "=== Gerador de Arquivo Passwd do Mosquitto ===\n\n";

try {
    // Lê credenciais do banco
    $stmt = $conn->prepare("
        SELECT 
            mqtt_username,
            mqtt_password_hash
        FROM mqtt_credentials
        WHERE enabled = 1
        ORDER BY mqtt_username
    ");
    $stmt->execute();
    
    $credentials = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    if (empty($credentials)) {
        echo "❌ Nenhuma credencial MQTT encontrada. Execute generate_mqtt_credentials.php primeiro.\n";
        exit(1);
    }
    
    echo "🔐 Gerando arquivo passwd para " . count($credentials) . " usuários...\n\n";
    
    // Tenta usar mosquitto_passwd se disponível
    $mosquitto_passwd = shell_exec('which mosquitto_passwd 2>/dev/null');
    
    if (!empty($mosquitto_passwd)) {
        // Usa binary do Mosquitto
        $passwd_file = '/tmp/mosquitto_passwd_temp';
        
        // Remove arquivo anterior se existe
        if (file_exists($passwd_file)) {
            unlink($passwd_file);
        }
        
        echo "✅ Usando mosquitto_passwd CLI (mais seguro)\n\n";
        
        // Cria arquivo passwd usando mosquitto_passwd para cada usuário
        // Nota: Precisamos das senhas em plain text para isto
        // Alternativa: usar o hash BCrypt do banco diretamente (menos compatível)
        
        // Para agora, vamos exibir instruções
        echo "⚠️  Para gerar arquivo passwd com Mosquitto, use:\n";
        echo "mosquitto_passwd -c /etc/mosquitto/passwd device_1\n";
        echo "mosquitto_passwd /etc/mosquitto/passwd device_2\n";
        echo "(insira a senha para cada dispositivo)\n\n";
        
        echo "❓ Alternativa: Usar hashes do banco directly:\n";
    }
    
    // Cria arquivo passwd com hashes do banco
    $passwd_file = MOSQUITTO_CONF_DIR . '/passwd';
    
    if (!is_dir(MOSQUITTO_CONF_DIR)) {
        $passwd_file = '/tmp/mosquitto_passwd';
        echo "⚠️  Diretório do Mosquitto não existe. Salvando em: $passwd_file\n";
    }
    
    // Gera conteúdo
    // Nota: Mosquitto 2.x espera hashes PBKDF2 SHA256 no formato: $7$base64...
    // Vamos exportar em formato texto legível primeiro
    $passwd_content = "# Auto-gerado por generate_mosquitto_passwd.php\n";
    $passwd_content .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n";
    $passwd_content .= "# Formato: username:password_hash\n\n";
    
    $passwords = [];
    foreach ($credentials as $cred) {
        $username = $cred['mqtt_username'];
        
        // Para Mosquitto, precisamos de hash compatível
        // Vamos usar OpenSSL para gerar hash PBKDF2
        // Ou exportar em formato que possa ser importado
        
        // Lê password da tabela device_secrets se criada
        // Por enquanto, vamos apenas notificar que precisa ser feito via CLI
        
        echo "📝 Usuário: $username\n";
    }
    
    // Escreve um arquivo helper com instruções
    $helper_content = "#!/bin/bash\n";
    $helper_content .= "# Script para gerar arquivo passwd do Mosquitto\n";
    $helper_content .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n\n";
    $helper_content .= "PASSWD_FILE=/etc/mosquitto/passwd\n\n";
    $helper_content .= "# Cria arquivo vazio\n";
    $helper_content .= "sudo touch \$PASSWD_FILE\n";
    $helper_content .= "sudo chmod 600 \$PASSWD_FILE\n\n";
    
    foreach ($credentials as $cred) {
        $username = $cred['mqtt_username'];
        $helper_content .= "# Adicionar $username (será solicitada a senha)\n";
        $helper_content .= "# sudo mosquitto_passwd \$PASSWD_FILE $username\n";
    }
    
    $helper_file = ROOT_DIR . '/mqtt_setup_passwd.sh';
    file_put_contents($helper_file, $helper_content);
    chmod($helper_file, 0755);
    
    echo "\n✅ Script de setup gerado!\n";
    echo "📄 Arquivo: $helper_file\n\n";
    
    echo "=== Como Usar ===\n";
    echo "Opção 1: Usar mosquitto_passwd manualmente\n";
    echo "  sudo mosquitto_passwd -c /etc/mosquitto/passwd device_1\n";
    echo "  sudo mosquitto_passwd /etc/mosquitto/passwd device_2\n";
    echo "  (Repita para cada dispositivo)\n\n";
    
    echo "Opção 2: Usar script Python para gerar hashes PBKDF2\n";
    echo "  python3 src/mqtt/generate_mqtt_hashes.py\n\n";
    
    echo "=== Próximos Passos ===\n";
    echo "1. Gerar arquivo ACL:\n";
    echo "   php src/mqtt/generate_mosquitto_acl.php\n\n";
    echo "2. Configurar mosquitto.conf com:\n";
    echo "   allow_anonymous false\n";
    echo "   password_file /etc/mosquitto/passwd\n";
    echo "   acl_file /etc/mosquitto/conf.d/acl.acl\n\n";
    echo "3. Recarregar Mosquitto:\n";
    echo "   sudo systemctl reload mosquitto\n\n";
    
} catch (\Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
?>

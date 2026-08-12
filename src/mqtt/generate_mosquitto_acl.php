#!/usr/bin/env php
<?php
/**
 * generate_mosquitto_acl.php
 * Gera arquivo ACL do Mosquitto a partir das credenciais no banco
 * 
 * ACL format (para Mosquitto 2.x):
 * user device_1
 * topic readwrite mqtt/projects/+/devices/1
 * 
 * topic read mqtt/projects/+/devices/+
 * topic write mqtt/projects/{project}/devices/{device}
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('CONFIG_DIR', ROOT_DIR . '/src/config');
define('MOSQUITTO_CONF_DIR', '/etc/mosquitto/conf.d');

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';

echo "=== Gerador de ACL do Mosquitto ===\n\n";

try {
    // Lê credenciais do banco
    $stmt = $conn->prepare("
        SELECT 
            mc.device_id,
            mc.mqtt_username,
            d.project_id,
            d.name as device_name
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
    
    echo "📋 Gerando ACL para " . count($credentials) . " dispositivos...\n\n";
    
    // Gera conteúdo do ACL
    $acl_content = "# Auto-gerado por generate_mosquitto_acl.php\n";
    $acl_content .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n";
    $acl_content .= "# NÃO EDITE MANUALMENTE\n\n";
    
    // Padrão: cada dispositivo pode APENAS publicar/subscrever seus próprios tópicos
    // mqtt/projects/{project_id}/devices/{device_id}
    
    foreach ($credentials as $cred) {
        $device_id = $cred['device_id'];
        $username = $cred['mqtt_username'];
        $project_id = $cred['project_id'];
        
        // User block
        $acl_content .= "user {$username}\n";
        
        // Permissão de leitura e escrita apenas no seu próprio tópico de dispositivo
        $acl_content .= "topic readwrite mqtt/projects/{$project_id}/devices/{$device_id}\n";
        
        // Permissão de leitura em tópicos de status do projeto (broadcast)
        $acl_content .= "topic read mqtt/projects/{$project_id}/status\n";
        
        // Permissão de leitura em tópicos de configuração
        $acl_content .= "topic read mqtt/projects/{$project_id}/config\n";
        
        $acl_content .= "\n";
    }
    
    // Define super-user (admin) - pode fazer qualquer coisa
    $acl_content .= "user admin\n";
    $acl_content .= "topic readwrite #\n\n";
    
    // Define padrão para usuários não reconhecidos (negar todos)
    $acl_content .= "pattern read \$SYS/broker/clients/connected\n";
    $acl_content .= "pattern read \$SYS/broker/clients/disconnected\n";
    
    // Tenta salvar nel diretório do Mosquitto
    $acl_file = MOSQUITTO_CONF_DIR . '/acl.acl';
    
    // Se não tem permissão, salva em /tmp
    if (!is_writable(MOSQUITTO_CONF_DIR)) {
        $acl_file = '/tmp/mosquitto_acl.acl';
        echo "⚠️  Sem permissão em " . MOSQUITTO_CONF_DIR . ". Salvando em: $acl_file\n";
        echo "    Depois mova com: sudo mv $acl_file /etc/mosquitto/conf.d/acl.acl\n\n";
    }
    
    // Escreve arquivo
    if (file_put_contents($acl_file, $acl_content) === false) {
        echo "❌ Erro: Impossível escrever em $acl_file\n";
        exit(1);
    }
    
    chmod($acl_file, 0644);
    
    echo "✅ ACL gerado com sucesso!\n";
    echo "📁 Arquivo: $acl_file\n";
    echo "📊 Dispositivos configurados: " . count($credentials) . "\n\n";
    
    // Mostra exemplo
    echo "=== Exemplo de ACL ===\n";
    $preview = array_slice(explode("\n", $acl_content), 0, 10);
    foreach ($preview as $line) {
        echo $line . "\n";
    }
    echo "... (mais linhas)\n\n";
    
    echo "=== Próximos Passos ===\n";
    echo "1. Gerar arquivo passwd:\n";
    echo "   php src/mqtt/generate_mosquitto_passwd.php\n\n";
    echo "2. Se está em /tmp, copie para Mosquitto:\n";
    echo "   sudo cp $acl_file " . MOSQUITTO_CONF_DIR . "/acl.acl\n\n";
    echo "3. Recarregue configuração do Mosquitto:\n";
    echo "   sudo systemctl reload mosquitto\n\n";
    
} catch (\Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
?>

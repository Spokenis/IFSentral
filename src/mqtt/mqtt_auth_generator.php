#!/usr/bin/env php
<?php
/**
 * mqtt_auth_generator.php - Gera arquivo de autenticação para Mosquitto
 * Execute manualmente: php src/mqtt/mqtt_auth_generator.php
 * Ou adicione a um cron job: 0 * * * * cd /var/www/html/minha-api-php && php src/mqtt/mqtt_auth_generator.php
 */

// Define diretórios
define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('SRC_DIR', ROOT_DIR . '/src');
define('CONFIG_DIR', SRC_DIR . '/config');

// Carrega configurações
require_once CONFIG_DIR . '/config.php';
require_once CONFIG_DIR . '/db.php';

$mosquittoConfigDir = '/etc/mosquitto/conf.d';
$mosquittoAclFile = $mosquittoConfigDir . '/acl.acl';
$mosquittoPasswdFile = $mosquittoConfigDir . '/passwd';

function log_msg($msg, $level = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] [$level] $msg\n";
}

log_msg('=== Gerando configuração MQTT de autenticação ===', 'INFO');

try {
    // Verifica se diretório existe
    if (!is_dir($mosquittoConfigDir)) {
        log_msg("Diretório $mosquittoConfigDir não encontrado", 'ERROR');
        exit(1);
    }

    // 1. Gera arquivo de ACL
    log_msg("Gerando ACL file: $mosquittoAclFile", 'INFO');
    
    $aclContent = "# ACL do MQTT - Gerado automaticamente\n";
    $aclContent .= "# Cada dispositivo só pode publicar no seu próprio tópico\n\n";

    // Busca todos os dispositivos com tópicos permitidos
    $sql = "SELECT d.id, d.project_id, mc.mqtt_username, ma.topic_filter, ma.allow_publish, ma.allow_subscribe
            FROM devices d
            LEFT JOIN mqtt_credentials mc ON d.id = mc.device_id AND mc.enabled = 1
            LEFT JOIN mqtt_acl ma ON d.id = ma.device_id
            WHERE d.deletedAt IS NULL
            ORDER BY d.id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($devices)) {
        log_msg('Nenhum dispositivo encontrado', 'WARN');
    } else {
        log_msg('Processando ' . count($devices) . ' dispositivos', 'INFO');

        // Agrupa por username
        $aclByUser = [];
        foreach ($devices as $device) {
            if (!empty($device['mqtt_username'])) {
                $username = $device['mqtt_username'];
                $topic = $device['topic_filter'] ?? "mqtt/projects/{$device['project_id']}/devices/{$device['id']}";
                
                if (!isset($aclByUser[$username])) {
                    $aclByUser[$username] = [];
                }
                
                // Adiciona permissão de publish (padrão)
                $aclByUser[$username][] = "topic write " . $topic;
            }
        }

        // Escreve ACL
        foreach ($aclByUser as $username => $topics) {
            $aclContent .= "user $username\n";
            foreach ($topics as $topic) {
                $aclContent .= "  $topic\n";
            }
            $aclContent .= "\n";
        }

        // Permite que o worker subscriber possa subscrever
        $aclContent .= "user mqtt_subscriber\n";
        $aclContent .= "  topic read mqtt/projects/+/devices/+\n";
    }

    file_put_contents($mosquittoAclFile, $aclContent);
    log_msg("ACL file gerado com sucesso: $mosquittoAclFile", 'INFO');

    // 2. Gera arquivo de senhas (se não existir, cria com exemplo)
    if (!file_exists($mosquittoPasswdFile)) {
        log_msg("Criando arquivo de senhas: $mosquittoPasswdFile", 'INFO');
        
        // Para gerar: sudo mosquitto_passwd -c /etc/mosquitto/conf.d/passwd username
        // Por enquanto apenas cria um arquivo vazio com comentário
        $passwdContent = "# Use: sudo mosquitto_passwd /etc/mosquitto/conf.d/passwd username\n";
        file_put_contents($mosquittoPasswdFile, $passwdContent);
        chmod($mosquittoPasswdFile, 0640);
    }

    log_msg('Arquivo de senhas já existe, não foi recriado', 'INFO');

    // 3. Reload Mosquitto
    log_msg('Recarregando Mosquitto com nova configuração', 'INFO');
    $output = shell_exec('sudo mosquitto -T reload 2>&1');
    
    if (stripos($output, 'error') === false && stripos($output, 'failed') === false) {
        log_msg('Mosquitto recarregado com sucesso', 'INFO');
    } else {
        log_msg('Possível erro ao recarregar Mosquitto: ' . trim($output), 'WARN');
    }

    log_msg('=== Geração de autenticação MQTT concluída ===', 'INFO');

} catch (\Exception $e) {
    log_msg('Erro: ' . $e->getMessage(), 'ERROR');
    exit(1);
}
?>

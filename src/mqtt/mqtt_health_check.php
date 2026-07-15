#!/usr/bin/env php
<?php
/**
 * mqtt_health_check.php - Verifica saúde do worker MQTT
 * Execute: php src/mqtt/mqtt_health_check.php
 * Exemplo de cron: a cada 5 minutos executar este script
 */

define('ROOT_DIR', realpath(__DIR__ . '/../../'));
define('STATUS_FILE', ROOT_DIR . '/.mqtt_worker_status.json');
define('PID_FILE', ROOT_DIR . '/.mqtt_worker.pid');
define('LOG_FILE', ROOT_DIR . '/logs/mqtt_health_check.log');

// Função de log
function log_health($message, $level = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    $log_dir = dirname(LOG_FILE);
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_line = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $log_line, FILE_APPEND);
}

// 1. Verificar se PID existe
if (file_exists(PID_FILE)) {
    $pid = intval(file_get_contents(PID_FILE));
    $process_running = posix_getpgid($pid) !== false;
    
    if ($process_running) {
        // Processo está rodando
        $status = [
            'status' => 'healthy',
            'pid' => $pid,
            'last_check' => date('Y-m-d H:i:s'),
            'uptime' => 'running'
        ];
        
        file_put_contents(STATUS_FILE, json_encode($status, JSON_PRETTY_PRINT));
        log_health("MQTT Worker (PID $pid) está saudável", 'INFO');
        exit(0);
    } else {
        // Processo morreu
        log_health("MQTT Worker (PID $pid) foi encerrado inesperadamente. Reiniciando...", 'WARN');
        unlink(PID_FILE);
    }
} else {
    log_health("MQTT Worker não está rodando. Iniciando...", 'WARN');
}

// 2. Se chegou aqui, precisa iniciar o worker
require ROOT_DIR . '/src/config/config.php';
require ROOT_DIR . '/src/config/mqtt.php';

$worker_script = ROOT_DIR . '/src/mqtt/mqtt_subscriber.php';

if (!file_exists($worker_script)) {
    log_health("Erro: Script do worker não encontrado em $worker_script", 'ERROR');
    exit(1);
}

// Iniciar processo em background
$cmd = "cd " . escapeshellarg(ROOT_DIR) . " && nohup php " . escapeshellarg($worker_script) . " > /dev/null 2>&1 & echo $!";
$output = shell_exec($cmd);
$new_pid = intval(trim($output));

if ($new_pid > 0) {
    file_put_contents(PID_FILE, $new_pid);
    
    $status = [
        'status' => 'healthy',
        'pid' => $new_pid,
        'last_check' => date('Y-m-d H:i:s'),
        'last_restart' => date('Y-m-d H:i:s'),
        'uptime' => 'just started'
    ];
    
    file_put_contents(STATUS_FILE, json_encode($status, JSON_PRETTY_PRINT));
    log_health("MQTT Worker iniciado com PID $new_pid", 'INFO');
    exit(0);
} else {
    log_health("Falha ao iniciar MQTT Worker", 'ERROR');
    exit(1);
}
?>

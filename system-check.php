#!/usr/bin/env php
<?php
/**
 * system-check.php - Valida saúde completa do sistema
 * Execute: php system-check.php
 */

define('ROOT_DIR', __DIR__);

$OK = '✅';
$FAIL = '❌';
$WARN = '⚠️';

echo "\n" . str_repeat('=', 80) . "\n";
echo "🔍 VERIFICAÇÃO DO SISTEMA - IFSentral\n";
echo str_repeat('=', 80) . "\n\n";

$checks = [
    'config' => false,
    'db' => false,
    'schema' => false,
    'mqtt' => false,
    'files' => false,
    'permissions' => false,
];

// 1. VERIFICAR CONFIGURAÇÃO
echo "1️⃣  VERIFICANDO CONFIGURAÇÃO...\n";
if (file_exists(ROOT_DIR . '/src/config/config.php')) {
    echo "   $OK Arquivo config.php encontrado\n";
    $checks['config'] = true;
} else {
    echo "   $FAIL Arquivo config.php não encontrado\n";
}

if (file_exists(ROOT_DIR . '/src/config/.env')) {
    echo "   $OK Arquivo .env encontrado\n";
} else {
    echo "   $WARN Arquivo .env não encontrado (usando defaults)\n";
}

// 2. VERIFICAR BANCO DE DADOS
echo "\n2️⃣  VERIFICANDO BANCO DE DADOS...\n";
try {
    require ROOT_DIR . '/src/config/config.php';
    require ROOT_DIR . '/src/config/db.php';
    echo "   $OK Conexão com banco de dados OK\n";
    $checks['db'] = true;

    // Verificar schema
    echo "\n3️⃣  VERIFICANDO SCHEMA DO BANCO...\n";
    require ROOT_DIR . '/src/core/SchemaValidator.php';
    
    $validator = new \App\Core\SchemaValidator($conn);
    $result = $validator->validateSchema();
    
    echo $validator->getFormattedReport() . "\n";
    $checks['schema'] = $result['valid'];

} catch (Exception $e) {
    echo "   $FAIL Erro ao conectar: " . $e->getMessage() . "\n";
}

// 3. VERIFICAR MQTT
echo "\n4️⃣  VERIFICANDO MQTT...\n";
if (file_exists(ROOT_DIR . '/src/config/mqtt.php')) {
    echo "   $OK Configuração MQTT encontrada\n";

    if (file_exists(ROOT_DIR . '/.mqtt_worker.pid')) {
        // Deploy legado (sem Docker): worker roda como processo em background
        // no mesmo host, gerenciado por deploy-production.sh / mqtt_health_check.php.
        $pid = intval(file_get_contents(ROOT_DIR . '/.mqtt_worker.pid'));
        if (posix_getpgid($pid) !== false) {
            echo "   $OK MQTT Worker rodando com PID $pid\n";
            $checks['mqtt'] = true;
        } else {
            echo "   $FAIL MQTT Worker não está rodando (PID: $pid)\n";
        }
    } else {
        // Deploy Docker (padrão do README): o worker roda no container
        // separado 'worker', não neste container 'web' — não existe PID
        // local para checar. Os dois containers compartilham o volume
        // ./logs, então inferimos a saúde pela última linha relevante que o
        // worker gravou em mqtt_subscriber.log.
        $worker_log = ROOT_DIR . '/logs/mqtt_subscriber.log';
        if (file_exists($worker_log)) {
            $lines = file($worker_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $tail = array_slice($lines, -200);

            $last_connected_at = null;
            $last_error_at = null;
            foreach ($tail as $i => $line) {
                // Cobre tanto a conexão inicial quanto uma reconexão bem-sucedida
                // após queda momentânea do broker (ex.: `docker compose restart
                // mosquitto`) — o worker sempre loga um [ERROR] antes de tentar
                // reconectar, então isso sozinho não indica falha atual.
                if (
                    str_contains($line, 'Conectado ao broker MQTT com sucesso')
                    || str_contains($line, 'Reconectado ao broker com sucesso')
                ) {
                    $last_connected_at = $i;
                } elseif (str_contains($line, '[ERROR]') || str_contains($line, '[FATAL]')) {
                    $last_error_at = $i;
                }
            }

            if ($last_connected_at !== null && ($last_error_at === null || $last_error_at < $last_connected_at)) {
                echo "   $OK MQTT Worker conectado ao broker (container 'worker', via logs/mqtt_subscriber.log)\n";
                $checks['mqtt'] = true;
            } elseif ($last_error_at !== null) {
                echo "   $FAIL MQTT Worker com erro recente em logs/mqtt_subscriber.log — veja: docker compose logs worker\n";
            } else {
                echo "   $WARN MQTT Worker não foi iniciado ainda (sem registro de conexão em logs/mqtt_subscriber.log)\n";
            }
        } else {
            echo "   $WARN MQTT Worker não foi iniciado ainda (logs/mqtt_subscriber.log não existe)\n";
        }
    }
} else {
    echo "   $FAIL Configuração MQTT não encontrada\n";
}

// 4. VERIFICAR ESTRUTURA DE PASTAS
echo "\n5️⃣  VERIFICANDO ESTRUTURA DE PASTAS...\n";
$required_dirs = [
    'src/api',
    'src/auth',
    'src/config',
    'src/core',
    'src/db',
    'src/pages',
    'src/mqtt',
    'logs',
    'uploads/profile',
];

$all_dirs_exist = true;
foreach ($required_dirs as $dir) {
    $path = ROOT_DIR . '/' . $dir;
    if (is_dir($path)) {
        echo "   $OK $dir/\n";
    } else {
        echo "   $FAIL $dir/ (não encontrado)\n";
        @mkdir($path, 0755, true);
        $all_dirs_exist = false;
    }
}
$checks['files'] = $all_dirs_exist;

// 5. VERIFICAR PERMISSÕES
echo "\n6️⃣  VERIFICANDO PERMISSÕES...\n";
$writable_dirs = [
    'logs',
    'uploads',
];

$all_writable = true;
foreach ($writable_dirs as $dir) {
    $path = ROOT_DIR . '/' . $dir;
    if (is_writable($path)) {
        echo "   $OK $dir/ (escrita ok)\n";
    } else {
        echo "   $FAIL $dir/ (sem permissão de escrita)\n";
        @chmod($path, 0755);
        $all_writable = false;
    }
}
$checks['permissions'] = $all_writable;

// 6. RESUMO
echo "\n" . str_repeat('=', 80) . "\n";
echo "📊 RESUMO DA VERIFICAÇÃO\n";
echo str_repeat('=', 80) . "\n\n";

$total = count($checks);
$passed = array_sum($checks);
$percentage = ($passed / $total) * 100;

foreach ($checks as $check => $status) {
    $icon = $status ? $OK : $FAIL;
    echo "$icon " . ucfirst($check) . "\n";
}

echo "\n";
if ($percentage === 100) {
    echo "🎉 SISTEMA 100% OPERACIONAL!\n\n";
    echo "Próximos passos:\n";
    echo "  1. Inicie o MQTT Worker: php src/mqtt/mqtt_subscriber.php\n";
    echo "  2. Configure cron job: */5 * * * * cd " . ROOT_DIR . " && php src/mqtt/mqtt_health_check.php\n";
    exit(0);
} else if ($percentage >= 80) {
    echo "⚠️  SISTEMA COM AVISOS ($percentage%)\n";
    echo "Execute: php system-check.php para detalhes\n\n";
    exit(0);
} else {
    echo "❌ SISTEMA COM ERROS ($percentage%)\n";
    echo "Corrija os problemas acima antes de usar em produção\n\n";
    exit(1);
}
?>

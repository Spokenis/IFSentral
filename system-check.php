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
        $pid = intval(file_get_contents(ROOT_DIR . '/.mqtt_worker.pid'));
        if (posix_getpgid($pid) !== false) {
            echo "   $OK MQTT Worker rodando com PID $pid\n";
            $checks['mqtt'] = true;
        } else {
            echo "   $FAIL MQTT Worker não está rodando (PID: $pid)\n";
        }
    } else {
        echo "   $WARN MQTT Worker não foi iniciado ainda\n";
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

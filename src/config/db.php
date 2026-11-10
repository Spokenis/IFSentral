<?php
// Carrega config.php se não foi carregado ainda
if (!defined('DB_HOST')) {
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } elseif (file_exists(__DIR__ . '/../config/config.php')) {
        require_once __DIR__ . '/../config/config.php';
    } else {
        @require_once 'config.php';
    }
}

// Usa os valores já resolvidos por config.php (env(): variável de ambiente
// do sistema tem prioridade sobre o arquivo .env). Reimplementar essa
// prioridade aqui divergia da ordem real e fazia um src/config/.env
// desatualizado sobrepor silenciosamente credenciais já definidas no SO.
$host = DB_HOST;
$dbname = DB_NAME;
$user = DB_USER;
$pass = DB_PASS;
$env = APP_ENV;

try {
    $conn = new PDO(
        "mysql:host=" . $host . ";dbname=" . $dbname . ";charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    // Mesmo fuso do PHP (config.php): NOW(), CURRENT_TIMESTAMP e as colunas
    // TIMESTAMP passam a vir no horário local
    $conn->exec("SET time_zone = '" . date('P') . "'");
} catch(PDOException $e) {
    if (!headers_sent()) {
        // 503 + Retry-After: o banco está fora, não é erro da requisição; o
        // dispositivo (ou o navegador) deve tentar de novo em instantes
        http_response_code(503);
        header('Retry-After: 10');
        header('Content-Type: application/json; charset=UTF-8');
    }
    
    if ($env === 'production') {
        echo json_encode(['error' => 'Falha na conexão com o banco de dados.']);
    } else {
        echo json_encode(['error' => 'Falha na conexão: ' . $e->getMessage()]);
    }
    exit;
}
?>
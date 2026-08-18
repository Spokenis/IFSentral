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

// Usa os valores já resolvidos por config.php (env(): getenv()/Docker Compose
// tem prioridade sobre o arquivo .env). Reimplementar essa prioridade aqui
// divergia da ordem real e fazia um src/config/.env desatualizado sobrepor
// silenciosamente as credenciais injetadas pelo docker-compose.yml.
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
} catch(PDOException $e) {
    if (!headers_sent()) {
        http_response_code(500);
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
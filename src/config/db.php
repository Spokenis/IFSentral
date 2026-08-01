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

// Prioriza variáveis de ambiente do Docker; usa as constantes como fallback
$host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'db');
$dbname = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'ifsentral_bd');
$user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'ifsentral_user');
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (defined('DB_PASS') ? DB_PASS : '');
$env = getenv('APP_ENV') ?: (defined('APP_ENV') ? APP_ENV : 'development');

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
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    
    if ($env === 'production') {
        echo json_encode(['error' => 'Falha na conexão com o banco de dados.']);
    } else {
        echo json_encode(['error' => 'Falha na conexão: ' . $e->getMessage()]);
    }
    exit;
}
?>
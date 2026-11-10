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
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Persistente: cada processo do Apache reaproveita a sua conexão
            // em vez de abrir e fechar uma por requisição. No teste de carga de
            // 08/11/2026, a ~1.000 req/s, o aperto de mão de milhares de
            // conexões por segundo enchia o MySQL e parte das requisições
            // recebia HTTP 500. O PDO desfaz transações deixadas abertas ao fim
            // de cada requisição e testa a conexão antes de reaproveitá-la.
            PDO::ATTR_PERSISTENT => defined('DB_PERSISTENT') && DB_PERSISTENT,
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
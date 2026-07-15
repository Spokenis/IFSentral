<?php
// db.php (Usando configurações de variáveis de ambiente via config.php)

// Carrega config.php se não foi carregado ainda
if (!defined('DB_HOST')) {
    // Tenta carregar com caminho relativo (para compatibilidade com includes antigos)
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } elseif (file_exists(__DIR__ . '/../config/config.php')) {
        require_once __DIR__ . '/../config/config.php';
    } else {
        // Se não conseguir encontrar, tenta com include_path
        require_once 'config.php';
    }
}

try {
    // Usando PDO com configurações do arquivo .env
    $conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    
    // Configura o PDO para lançar exceções em caso de erro
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch(PDOException $e) {
    http_response_code(500);
    // Em produção, não mostre a mensagem de erro completa
    if (APP_ENV === 'production') {
        echo json_encode(['error' => 'Falha na conexão com o banco de dados.']);
    } else {
        echo json_encode(['error' => 'Falha na conexão: ' . $e->getMessage()]);
    }
    exit;
}
?>
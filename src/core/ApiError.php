<?php
// ApiError.php - helper para respostas de erro seguras em APIs

// Garante que as constantes de configuração estejam carregadas
if (!defined('APP_ENV')) {
    if (file_exists(__DIR__ . '/../config/config.php')) {
        require_once __DIR__ . '/../config/config.php';
    }
}

function api_error_response($userMessage, $e = null, $httpCode = 500) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');

    $env = defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'production');

    if ($env === 'production') {
        echo json_encode(['error' => $userMessage]);
    } else {
        $detail = null;
        if ($e instanceof \Exception || $e instanceof \Throwable) {
            $detail = $e->getMessage();
        }
        echo json_encode(['error' => $userMessage, 'detail' => $detail]);
    }
    exit;
}

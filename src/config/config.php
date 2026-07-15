<?php
/**
 * config.php - Carrega variáveis de ambiente
 * Simples implementação sem dependências externas
 */

// Define valores padrão
$default_config = [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'u145233873_ifsentral_bd',
    'DB_USER' => 'u145233873_root',
    'DB_PASS' => '',
    'APP_ENV' => 'development',
    'APP_URL' => 'https://ifsentral.online/src/pages/index.html',
    'ALLOWED_ORIGINS' => 'https://ifsentral.online',
    'SESSION_SECURE' => false,
    'SESSION_HTTPONLY' => true,
    'SESSION_SAMESITE' => 'Lax'
];

// Carrega arquivo .env se existir
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignora comentários
        if (strpos($line, '#') === 0) continue;
        
        // Separa chave=valor
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove aspas externas e interpreta escapes comuns do .env
            if (preg_match('/^["\'](.+)["\']$/', $value, $matches)) {
                $value = str_replace(['\\"', '\\$', '\\\\'], ['"', '$', '\\'], $matches[1]);
            }
            
            $_ENV[$key] = $value;
        }
    }
}

// Função para obter configuração (com fallback para .env ou padrão)
function env($key, $default = null) {
    global $default_config;
    
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    
    if (isset($default_config[$key])) {
        return $default_config[$key];
    }
    
    return $default;
}

// Define constantes para fácil acesso
define('DB_HOST', env('DB_HOST'));
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));
define('APP_ENV', env('APP_ENV'));
define('APP_URL', env('APP_URL'));
define('ALLOWED_ORIGINS', env('ALLOWED_ORIGINS'));
// Força SESSION_SECURE=true automaticamente em produção
$session_secure = filter_var(env('SESSION_SECURE'), FILTER_VALIDATE_BOOLEAN);
if (env('APP_ENV') === 'production') {
    $session_secure = true;
    // Aviso se não estiver usando HTTPS
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        error_log('[AVISO] APP_ENV=production mas HTTPS não está ativo. Ative HTTPS para que SESSION_SECURE funcione corretamente.');
    }
}
define('SESSION_SECURE', $session_secure);
define('SESSION_HTTPONLY', filter_var(env('SESSION_HTTPONLY'), FILTER_VALIDATE_BOOLEAN));
define('SESSION_SAMESITE', env('SESSION_SAMESITE'));

/**
 * Função para configurar CORS seguro
 * Verifica se a origem solicitada está na lista de permitidas
 */
function setupSecureCORS() {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Define quais rotas são de acesso público/integração
    $isPublicApi = strpos($requestUri, '/api/enviar_payload.php') !== false || 
                   strpos($requestUri, '/api/buscar_payloads.php') !== false ||
                   strpos($requestUri, '/api/ttn_webhook.php') !== false ||
                   strpos($requestUri, '/get_mqtt_credentials.php') !== false;

    if ($isPublicApi) {
        // Aberto para qualquer front-end externo consumir (Segurança via X-Api-Key)
        header("Access-Control-Allow-Origin: *");
    } else {
        // Rotas internas do painel IFSentral (Segurança via Sessão e CORS)
        $allowedOrigins = explode(',', ALLOWED_ORIGINS);
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($requestOrigin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: " . $requestOrigin);
        }
    }
    
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Api-Key, x-api-key, Accept");
    header("Access-Control-Max-Age: 86400");

    // Retorna 200 para requisições preflight do navegador
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Função para setar configurações seguras de sessão
 */
function setupSecureSession($lifetime = 0) {
    // $lifetime em segundos; 0 = até fechar o navegador
    if (session_status() == PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => intval($lifetime),
            'secure' => SESSION_SECURE,      // Apenas HTTPS em produção
            'httponly' => SESSION_HTTPONLY,  // Não acessível via JavaScript
            'samesite' => SESSION_SAMESITE   // Proteção contra CSRF
        ]);
        session_start();
    }
}
?>

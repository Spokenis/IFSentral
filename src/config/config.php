<?php
/**
 * config.php - Carrega variáveis de ambiente
 * Suporte nativo para Docker Compose (getenv/OS) e arquivo .env
 */

// Define valores padrão compatíveis com o Docker Compose
$default_config = [
    'DB_HOST' => 'db',
    'DB_NAME' => 'ifsentral_bd',
    'DB_USER' => 'ifsentral_user',
    'DB_PASS' => 'secretpassword',
    'APP_ENV' => 'production',
    'APP_URL' => 'https://ifsentral.online',
    'ALLOWED_ORIGINS' => 'https://ifsentral.online',
    'SESSION_SECURE' => false,
    'SESSION_HTTPONLY' => true,
    'SESSION_SAMESITE' => 'Lax',
    
    // Configurações Padrão de E-mail
    'SMTP_HOST' => 'smtp.hostinger.com',
    'SMTP_PORT' => 465,
    'SMTP_USER' => 'suporte@ifsentral.online',
    'SMTP_PASS' => '',
    'SMTP_ENCRYPTION' => 'ssl',
    'MAIL_FROM_ADDRESS' => 'suporte@ifsentral.online',
    'MAIL_FROM_NAME' => 'IFSentral Smart Campus'
];

// Carrega arquivo .env se existir (para ambientes fora do Docker ou overrides locais)
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            if (preg_match('/^["\'](.+)["\']$/', $value, $matches)) {
                $value = str_replace(['\\"', '\\$', '\\\\'], ['"', '$', '\\'], $matches[1]);
            }
            
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Função para obter configuração
 * Prioridade: getenv() (Docker/OS) -> $_SERVER -> $_ENV (.env) -> Padrão
 */
function env($key, $default = null) {
    global $default_config;
    
    // 1. Variáveis de ambiente nativas (injetadas pelo Docker Compose)
    $sys_val = getenv($key);
    if ($sys_val !== false) {
        return $sys_val;
    }
    
    // 2. Variáveis de servidor do Apache/PHP
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    
    // 3. Variáveis carregadas do arquivo .env
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    
    // 4. Fallback para o array de padrões
    if (isset($default_config[$key])) {
        return $default_config[$key];
    }
    
    return $default;
}

// Define constantes para fácil acesso (Banco de Dados e Aplicação)
define('DB_HOST', env('DB_HOST'));
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));
define('APP_ENV', env('APP_ENV'));
define('APP_URL', env('APP_URL'));
define('ALLOWED_ORIGINS', env('ALLOWED_ORIGINS'));

// Define constantes para E-mail (SMTP)
define('ENABLE_EMAIL_FEATURES', filter_var(env('ENABLE_EMAIL_FEATURES', true), FILTER_VALIDATE_BOOLEAN));
define('SMTP_HOST', env('SMTP_HOST'));
define('SMTP_PORT', env('SMTP_PORT'));
define('SMTP_USER', env('SMTP_USER'));
define('SMTP_PASS', env('SMTP_PASS'));
define('SMTP_ENCRYPTION', env('SMTP_ENCRYPTION'));
define('MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME'));

// Força SESSION_SECURE=true automaticamente em produção
$session_secure = filter_var(env('SESSION_SECURE'), FILTER_VALIDATE_BOOLEAN);
if (env('APP_ENV') === 'production') {
    $session_secure = true;
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        error_log('[AVISO] APP_ENV=production mas HTTPS não está ativo. Ative HTTPS para que SESSION_SECURE funcione corretamente.');
    }
}
define('SESSION_SECURE', $session_secure);
define('SESSION_HTTPONLY', filter_var(env('SESSION_HTTPONLY'), FILTER_VALIDATE_BOOLEAN));
define('SESSION_SAMESITE', env('SESSION_SAMESITE'));

/**
 * Função para configurar CORS seguro
 */
function setupSecureCORS() {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    $isPublicApi = strpos($requestUri, '/api/enviar_payload.php') !== false || 
                   strpos($requestUri, '/api/buscar_payloads.php') !== false ||
                   strpos($requestUri, '/api/ttn_webhook.php') !== false ||
                   strpos($requestUri, '/get_mqtt_credentials.php') !== false;

    if ($isPublicApi) {
        header("Access-Control-Allow-Origin: *");
    } else {
        $allowedOrigins = explode(',', ALLOWED_ORIGINS);
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($requestOrigin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: " . $requestOrigin);
        }
    }
    
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Api-Key, x-api-key, Accept");
    header("Access-Control-Max-Age: 86400");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Função para setar configurações seguras de sessão
 */
function setupSecureSession($lifetime = 0) {
    if (session_status() == PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => intval($lifetime),
            'secure' => SESSION_SECURE,
            'httponly' => SESSION_HTTPONLY,
            'samesite' => SESSION_SAMESITE
        ]);
        session_start();
    }
}
?>
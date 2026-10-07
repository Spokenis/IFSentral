<?php
/**
 * config.php - Carrega variáveis de ambiente
 * Lê de getenv()/$_SERVER (se o host as expuser), de env.php ou do arquivo .env
 */

require_once __DIR__ . '/../core/Url.php';

// Valores padrão usados quando não há env.php/.env nem variável de ambiente
// definida. Edite src/config/env.php (copie de env.example.php) com as
// credenciais reais do servidor — não é necessário alterar os valores abaixo.
$default_config = [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'ifsentral_bd',
    'DB_USER' => 'ifsentral_user',
    'DB_PASS' => 'secretpassword',
    'APP_ENV' => 'production',
    // Vazio = usa o endereço da própria requisição (ver app_absolute_url())
    'APP_URL' => '',
    'ALLOWED_ORIGINS' => '',
    // Redireciona HTTP -> HTTPS. Só ative se o servidor tiver certificado.
    'FORCE_HTTPS' => false,
    'SESSION_HTTPONLY' => true,
    'SESSION_SAMESITE' => 'Lax',
    
    // Configurações Padrão de E-mail
    'SMTP_HOST' => 'smtp.hostinger.com',
    'SMTP_PORT' => 465,
    'SMTP_USER' => 'suporte@ifsentral.online',
    'SMTP_PASS' => '',
    'SMTP_ENCRYPTION' => 'ssl',
    'MAIL_FROM_ADDRESS' => 'suporte@ifsentral.online',
    'MAIL_FROM_NAME' => 'IFSentral Lite Smart Campus'
];

// Carrega arquivo .env se existir (prioriza src/config/.env, senão tenta o .env da raiz)
$env_file = __DIR__ . '/.env';
if (!file_exists($env_file)) {
    $root_env = dirname(__DIR__, 2) . '/.env';
    if (file_exists($root_env)) {
        $env_file = $root_env;
    }
}

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

// env.php (opcional, tem prioridade sobre o .env): mesmo conteúdo, mas num
// arquivo PHP. Ao contrário do .env, ele nunca é entregue como texto pelo
// servidor web — é a opção segura quando o .htaccess não é respeitado.
if (file_exists(__DIR__ . '/env.php')) {
    $php_env = require __DIR__ . '/env.php';
    if (is_array($php_env)) {
        foreach ($php_env as $key => $value) {
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Função para obter configuração
 * Prioridade: getenv() (variável de ambiente do SO) -> $_SERVER -> $_ENV (env.php/.env) -> Padrão
 */
function env($key, $default = null) {
    global $default_config;

    // 1. Variáveis de ambiente nativas do sistema/Apache (SetEnv, painel de hospedagem, etc.)
    $sys_val = getenv($key);
    if ($sys_val !== false) {
        return $sys_val;
    }

    // 2. Variáveis de servidor do Apache/PHP
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    
    // 3. Variáveis carregadas de env.php ou do arquivo .env
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

define('FORCE_HTTPS', filter_var(env('FORCE_HTTPS'), FILTER_VALIDATE_BOOLEAN));

// O cookie de sessão só leva a flag Secure quando a requisição é HTTPS: com
// a flag ligada em um servidor só HTTP o navegador descarta o cookie e o
// login nunca se mantém.
define('SESSION_SECURE', is_https_request());
define('SESSION_HTTPONLY', filter_var(env('SESSION_HTTPONLY'), FILTER_VALIDATE_BOOLEAN));
define('SESSION_SAMESITE', env('SESSION_SAMESITE'));

// Nome e caminho próprios para o cookie de sessão: em uma subpasta
// (/site1/) o servidor pode hospedar outros sites PHP no mesmo domínio, e
// com o PHPSESSID padrão em "/" as sessões se misturariam entre eles.
// Definido via ini para valer também nos session_start() diretos.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
    ini_set('session.name', 'IFSENTRALSESSID');
    ini_set('session.cookie_path', app_base_path() . '/');
    ini_set('session.cookie_secure', SESSION_SECURE ? '1' : '0');
    ini_set('session.cookie_httponly', SESSION_HTTPONLY ? '1' : '0');
    ini_set('session.cookie_samesite', SESSION_SAMESITE);
}

/**
 * Função para configurar CORS seguro
 */
function setupSecureCORS() {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    // Reconhece tanto a URL limpa atual quanto o caminho físico antigo
    // (com extensão .php), que o .htaccess mantém ativo por compatibilidade.
    $publicApiPatterns = [
        '/api/enviar-payload', '/src/api/enviar_payload.php',
        '/api/buscar-payloads', '/src/api/buscar_payloads.php',
        '/api/ttn-webhook', '/src/api/ttn_webhook.php',
    ];

    $isPublicApi = false;
    foreach ($publicApiPatterns as $pattern) {
        if (strpos($requestUri, $pattern) !== false) {
            $isPublicApi = true;
            break;
        }
    }

    if ($isPublicApi) {
        header("Access-Control-Allow-Origin: *");
    } else {
        $allowedOrigins = explode(',', ALLOWED_ORIGINS);
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: " . $requestOrigin);
            header("Vary: Origin");
        }
    }
    
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Api-Key, x-api-key, Accept, X-CSRF-Token");
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
            'path' => app_base_path() . '/',
            'secure' => SESSION_SECURE,
            'httponly' => SESSION_HTTPONLY,
            'samesite' => SESSION_SAMESITE
        ]);
        session_start();
    }
}
?>
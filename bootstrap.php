<?php
/**
 * bootstrap.php - Arquivo de inicialização central
 * Define paths e carrega arquivos essenciais
 */

// Define diretórios principais
define('ROOT_DIR', __DIR__);
define('SRC_DIR', ROOT_DIR . '/src');
define('CONFIG_DIR', SRC_DIR . '/config');
define('AUTH_DIR', SRC_DIR . '/auth');
define('API_DIR', SRC_DIR . '/api');
define('DB_DIR', SRC_DIR . '/db');
define('PAGES_DIR', SRC_DIR . '/pages');
define('DOCS_DIR', ROOT_DIR . '/docs');

// Carrega arquivo de configuração
require_once CONFIG_DIR . '/config.php';

// Carrega arquivo de banco de dados se existir
if (file_exists(CONFIG_DIR . '/db.php')) {
    require_once CONFIG_DIR . '/db.php';
}

// Função para incluir arquivos com segurança
function load_file($path) {
    if (file_exists($path)) {
        require_once $path;
    } else {
        trigger_error("Arquivo não encontrado: $path", E_USER_WARNING);
    }
}

// Função para carregar um arquivo do diretório de API
function load_api($file) {
    $path = API_DIR . '/' . basename($file);
    load_file($path);
}

// Função para carregar um arquivo do diretório de Auth
function load_auth($file) {
    $path = AUTH_DIR . '/' . basename($file);
    load_file($path);
}

// Função para carregar um arquivo do diretório de Pages
function load_page($file) {
    $path = PAGES_DIR . '/' . basename($file);
    load_file($path);
}

// Função para carregar um arquivo do diretório de Config
function load_config($file) {
    $path = CONFIG_DIR . '/' . basename($file);
    load_file($path);
}

// Auto-loader simples para classes
function autoload_class($class) {
    $parts = explode('\\', $class);
    $file = SRC_DIR . '/' . implode('/', $parts) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
}

spl_autoload_register('autoload_class');
?>

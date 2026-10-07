<?php
/**
 * Url.php - Monta URLs internas sem depender de onde o site está instalado
 *
 * O site pode estar na raiz do domínio (https://dominio/) ou numa subpasta
 * (http://servidor/site1/), com ou sem mod_rewrite. O index.php da raiz
 * descobre isso a cada requisição e define APP_BASE_PATH / APP_URL_PREFIX;
 * as funções abaixo só leem esses valores.
 */

/**
 * Detecta se a requisição usa HTTPS (direto ou via proxy)
 */
function is_https_request(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
           (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
}

/**
 * Pasta do site vista pelo navegador: '' na raiz do domínio, '/site1' em subpasta
 */
function app_base_path(): string {
    if (defined('APP_BASE_PATH')) {
        return APP_BASE_PATH;
    }

    // Fallback para arquivos acessados diretamente (sem passar pelo index.php):
    // compara o caminho físico do script com o caminho público dele.
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $file = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($file !== '' && str_starts_with($file, $root . '/')) {
        $relative = substr($file, strlen($root));
        if (str_ends_with($script, $relative)) {
            return substr($script, 0, -strlen($relative));
        }
    }
    return '';
}

/**
 * Prefixo das rotas: igual a app_base_path() quando há mod_rewrite, ou com
 * '/index.php' no final quando as rotas são servidas como /index.php/rota
 */
function app_url_prefix(): string {
    return defined('APP_URL_PREFIX') ? APP_URL_PREFIX : app_base_path();
}

/**
 * Caminho de uma rota para usar em redirects — ex.: app_url('meus-projetos')
 */
function app_url(string $route = ''): string {
    return app_url_prefix() . '/' . ltrim($route, '/');
}

/**
 * URL completa (com domínio) de uma rota, para links enviados por e-mail ou
 * mostrados na documentação. Usa APP_URL quando configurada; senão, o
 * endereço da própria requisição.
 */
function app_absolute_url(string $route = ''): string {
    if (defined('APP_URL') && APP_URL !== '') {
        // APP_URL já inclui a subpasta, se houver; falta só o '/index.php'
        // quando o servidor não tem mod_rewrite.
        $origin = rtrim(APP_URL, '/') . substr(app_url_prefix(), strlen(app_base_path()));
    } else {
        $origin = (is_https_request() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . app_url_prefix();
    }
    return $origin . '/' . ltrim($route, '/');
}

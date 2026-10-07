<?php
/**
 * index.php - Ponto de entrada único do site
 *
 * Toda página e toda chamada de API passa por aqui. A rota é lida da URL e
 * mapeada para o arquivo real em src/ pela tabela src/config/routes.php.
 *
 * Funciona nos dois cenários de servidor, sem configuração:
 *   - com .htaccess/mod_rewrite ativos:  /login            (URL limpa)
 *   - sem .htaccess (AllowOverride None): /index.php/login
 * e tanto na raiz do domínio quanto numa subpasta (ex.: /site1/), porque
 * todos os links do site são relativos.
 */

require_once __DIR__ . '/src/core/Url.php';

/**
 * Descobre a rota pedida, define APP_BASE_PATH / APP_URL_PREFIX e devolve o
 * arquivo que deve atender a requisição (ou encerra com redirect/404/arquivo
 * estático).
 */
function ifs_resolve_request(): string {
    // Pasta do site vista pelo navegador ('' na raiz, '/site1' em subpasta)
    $base = preg_replace('#/index\.php.*$#', '', $_SERVER['SCRIPT_NAME'] ?? '/index.php');

    $path = rawurldecode(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }

    // Chegou como /index.php/rota? Então a URL limpa não foi usada.
    $viaIndex = (bool) preg_match('#^/index\.php(/|$)#', $path);
    if ($viaIndex) {
        $path = substr($path, strlen('/index.php'));
    }
    $route = trim($path, '/');

    // O .htaccess marca a requisição quando o mod_rewrite está funcionando
    $hasRewrite = !empty($_SERVER['IFS_REWRITE']) || !empty($_SERVER['REDIRECT_IFS_REWRITE']);

    define('APP_BASE_PATH', $base);
    define('APP_URL_PREFIX', $viaIndex || (!$hasRewrite && $route === '') ? $base . '/index.php' : $base);

    $query = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';

    // Os links são relativos, então a URL da página precisa ter a forma
    // exata "prefixo/rota" (ou "prefixo/" na inicial) — sem isso o navegador
    // resolveria os links a partir da pasta errada.
    $isCanonical = $route === '' ? ($path === '/' && ($viaIndex || $hasRewrite)) : !str_ends_with($path, '/');
    if (!$isCanonical && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        // /index.php puro, com mod_rewrite ativo, volta para a URL limpa
        $target = ($route === '' && $hasRewrite) ? $base . '/' : app_url($route);
        header('Location: ' . $target . $query, true, 302);
        exit;
    }

    $routes = require __DIR__ . '/src/config/routes.php';
    if (isset($routes[$route])) {
        return __DIR__ . '/' . $routes[$route];
    }

    // Endereços antigos das páginas de administração (tinham dois níveis)
    $movedRoutes = ['admin/usuarios' => 'admin-usuarios', 'admin/rate-limiting' => 'admin-rate-limiting'];
    if (isset($movedRoutes[$route])) {
        header('Location: ' . app_url($movedRoutes[$route]) . $query, true, 301);
        exit;
    }

    ifs_serve_static($route);

    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8"><title>Página não encontrada | IFSentral Lite</title></head>'
       . '<body style="font-family:sans-serif;text-align:center;margin-top:15vh">'
       . '<h1>404</h1><p>Página não encontrada.</p>'
       . '<p><a href="' . htmlspecialchars(app_url()) . '">Voltar para a página inicial</a></p></body></html>';
    exit;
}

/**
 * Entrega JS/CSS/imagens quando o Apache não faz isso sozinho (sem
 * mod_rewrite, /assets/... não existe como pasta real). Só serve arquivos
 * de tipos conhecidos dentro das pastas listadas.
 */
function ifs_serve_static(string $route): void {
    $imageTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $folders = [
        'assets/' => [__DIR__ . '/src/assets', $imageTypes + [
            'js' => 'application/javascript; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ]],
        // Arquivos enviados por usuários: somente imagens, nunca SVG/HTML
        'uploads/profile/' => [__DIR__ . '/uploads/profile', $imageTypes],
    ];

    foreach ($folders as $prefix => [$dir, $types]) {
        if (!str_starts_with($route, $prefix)) {
            continue;
        }
        $root = realpath($dir);
        $file = realpath($dir . '/' . substr($route, strlen($prefix)));
        $ext = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
        if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) || !isset($types[$ext])) {
            return;
        }
        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
}

$ifsTarget = ifs_resolve_request();

require_once __DIR__ . '/src/config/config.php';
require_once __DIR__ . '/src/core/SecurityHeaders.php';
send_security_headers(FORCE_HTTPS);

// As páginas usam caminhos relativos nos seus require ('../config/...'),
// então precisam rodar a partir da própria pasta.
chdir(dirname($ifsTarget));

if (str_ends_with($ifsTarget, '.html')) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($ifsTarget);
    exit;
}

require $ifsTarget;

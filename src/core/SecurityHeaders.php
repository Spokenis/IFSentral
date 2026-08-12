<?php
/**
 * SecurityHeaders.php
 * Centraliza o envio de cabeçalhos de segurança via PHP como fallback
 */

function send_security_headers(bool $forceHttps = true): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    // Detecta se a requisição usa HTTPS (direto ou via proxy)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
               (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

    if ($forceHttps && !$isHttps) {
        // Evita redirecionamentos para caminhos internos de CLI/testing
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if ($host) {
            header('Location: https://' . $host . $uri, true, 301);
            exit;
        }
    }

    // HSTS: só se estamos em HTTPS
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload', true);
    }

    header('X-Frame-Options: SAMEORIGIN', true);
    header('X-Content-Type-Options: nosniff', true);
    header('Referrer-Policy: strict-origin-when-cross-origin', true);
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()", true);
    header('X-Download-Options: noopen', true);
    header('X-Permitted-Cross-Domain-Policies: none', true);

    // CSP: Permissiva para CDNs (Google Fonts, Cloudflare, JSDelivr)
    header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; connect-src 'self' ws: wss:;", true);
}

?>

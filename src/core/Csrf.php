<?php
/**
 * Csrf.php - Geração e validação de token CSRF (double-submit via header)
 */

namespace App\Core;

class Csrf
{
    const SESSION_KEY = 'csrf_token';

    /**
     * Retorna o token da sessão atual, gerando um novo se ainda não existir
     */
    public static function getToken()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Força a geração de um novo token (usar após login, junto de session_regenerate_id)
     */
    public static function regenerateToken()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Valida o token enviado pelo cliente no header X-CSRF-Token.
     * Encerra a requisição com 403 se ausente ou inválido.
     */
    public static function requireValidToken()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $headers = getallheaders();
        $sent = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? null;

        if (!$sent || empty($_SESSION[self::SESSION_KEY]) || !hash_equals($_SESSION[self::SESSION_KEY], $sent)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Token CSRF ausente ou inválido.']);
            exit;
        }
    }

    /**
     * Valida o token enviado via campo oculto de formulário HTML tradicional
     * (POST sem fetch/JSON, onde não há como anexar o header X-CSRF-Token).
     * Encerra a requisição com 403 se ausente ou inválido.
     */
    public static function requireValidTokenFromPost($fieldName = 'csrf_token')
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sent = $_POST[$fieldName] ?? null;

        if (!$sent || empty($_SESSION[self::SESSION_KEY]) || !hash_equals($_SESSION[self::SESSION_KEY], $sent)) {
            http_response_code(403);
            echo 'Token CSRF ausente ou inválido.';
            exit;
        }
    }
}
?>

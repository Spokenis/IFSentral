<?php
// src/api/obter_csrf_token.php
// Retorna o token CSRF da sessão atual (gera um novo se ainda não existir)

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/Csrf.php';

use App\Core\Csrf;

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../auth/auth_check.php';

echo json_encode(['csrf_token' => Csrf::getToken()]);
?>

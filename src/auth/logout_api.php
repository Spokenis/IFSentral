<?php
// logout_api.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

// Inicia a sessão para poder destruí-la
session_start();

// 1. Apaga todas as variáveis da sessão
$_SESSION = array();

// 2. Destrói a sessão
session_destroy();

// 3. Limpa cookie de sessão (caso tenha expiry prolongado)
$params = session_get_cookie_params();
setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);

// 4. Redireciona para a página de login
header('Location: login.html');
exit;
?>
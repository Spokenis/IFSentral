<?php
// auth_check.php

// Inicia a sessão para verificar os dados
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Se a sessão 'logged_in' não existir OU não for 'true',
// o usuário não está logado.
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Detectar se a requisição espera JSON (AJAX/fetch/API)
    $isAjax = false;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $isAjax = true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if ($accept && stripos($accept, 'application/json') !== false) {
        $isAjax = true;
    }
    $contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? ($_SERVER['CONTENT_TYPE'] ?? '');
    if ($contentType && stripos($contentType, 'application/json') !== false) {
        $isAjax = true;
    }

    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Não autenticado. Faça login primeiro.']);
        exit;
    } else {
        header('Location: login.html');
        exit;
    }
}

// Se o script continuar, o usuário está logado.
// Podemos até re-definir o nome de usuário em uma variável
// para facilitar o uso nas páginas.
$username_logado = $_SESSION['username'];
?>
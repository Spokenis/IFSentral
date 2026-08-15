<?php
// src/auth/verificar_email.php

require_once '../config/config.php';
require_once '../config/db.php';

// Função auxiliar para exibir mensagens e encerrar a execução da página com estilo
function renderizarPagina($titulo, $mensagem, $tipo = 'erro') {
    $corIcone = $tipo === 'sucesso' ? '#1B7D3D' : '#d9534f';
    $icone = $tipo === 'sucesso' ? '✔️' : '❌';
    
    echo "<!DOCTYPE html>
    <html lang='pt-br'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>{$titulo}</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7f6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .container { background-color: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; max-width: 500px; width: 90%; }
            .icone { font-size: 48px; color: {$corIcone}; margin-bottom: 20px; }
            h1 { color: #333; font-size: 24px; margin-top: 0; }
            p { color: #666; font-size: 16px; line-height: 1.5; margin-bottom: 30px; }
            .btn { display: inline-block; padding: 12px 24px; background-color: #1B7D3D; color: #fff; text-decoration: none; border-radius: 4px; font-weight: bold; transition: background-color 0.3s; }
            .btn:hover { background-color: #0D4620; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='icone'>{$icone}</div>
            <h1>{$titulo}</h1>
            <p>{$mensagem}</p>
            <a href='/login' class='btn'>Ir para o Login</a>
        </div>
    </body>
    </html>";
    exit;
}

// 1. Validar a presença do token na URL
if (!isset($_GET['token']) || empty(trim($_GET['token']))) {
    renderizarPagina("Token Ausente", "Nenhum código de verificação foi fornecido. Por favor, utilize o link exato enviado para o seu e-mail.");
}

$tokenPuro = trim($_GET['token']);
$tokenHash = hash('sha256', $tokenPuro);

try {
    // 2. Buscar o token no banco de dados
    $stmt = $conn->prepare("SELECT id, user_id, expires_at FROM email_verifications WHERE token_hash = ? AND type = 'REGISTER' AND used = 0");
    $stmt->execute([$tokenHash]);
    $verificacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verificacao) {
        renderizarPagina("Link Inválido", "Este link de verificação não é válido ou já foi utilizado anteriormente.");
    }

    // 3. Verificar se o token expirou
    if (strtotime($verificacao['expires_at']) < time()) {
        renderizarPagina("Link Expirado", "O seu link de verificação expirou. Por favor, solicite o reenvio do e-mail de confirmação.");
    }

    // 4. Token válido e no prazo: iniciar transação para atualizar os dados
    $conn->beginTransaction();

    // Atualiza o usuário para verificado
    $stmtUpdateUser = $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
    $stmtUpdateUser->execute([$verificacao['user_id']]);

    // Invalida o token para não ser usado novamente
    $stmtUpdateToken = $conn->prepare("UPDATE email_verifications SET used = 1 WHERE id = ?");
    $stmtUpdateToken->execute([$verificacao['id']]);

    $conn->commit();
    
    // 5. Exibir página de sucesso
    renderizarPagina("Conta Verificada!", "Seu e-mail foi confirmado com sucesso. Sua conta agora está ativa e pronta para uso.", "sucesso");

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Erro ao verificar email: " . $e->getMessage());
    renderizarPagina("Erro no Servidor", "Ocorreu um erro interno ao tentar verificar sua conta. Por favor, tente novamente mais tarde.");
}
?>
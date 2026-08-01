<?php
// cadastrar_usuario.php

require_once '../config/config.php';
require_once '../core/ServicoEmail.php'; // Incluindo a classe do carteiro
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

require '../config/db.php';

$data = json_decode(file_get_contents("php://input"));

if (
    !isset($data->name) ||
    !isset($data->email) ||
    !isset($data->username) ||
    !isset($data->password) ||
    empty($data->password)
) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Campos obrigatórios: name, email, username, e password.']);
    exit;
}

$profile = isset($data->profile) ? $data->profile : 'User';

// --- HASH DA SENHA ---
$password_hash = password_hash($data->password, PASSWORD_BCRYPT);

try {
    // Iniciar a transação para garantir que a conta e o token sejam salvos juntos
    $conn->beginTransaction();

$emailFeaturesEnabled = defined('ENABLE_EMAIL_FEATURES') && ENABLE_EMAIL_FEATURES === true;
    
    if ($emailFeaturesEnabled) {
        $sql = "INSERT INTO users (name, email, profile, username, password_hash) 
                VALUES (?, ?, ?, ?, ?)";
    } else {
        $sql = "INSERT INTO users (name, email, profile, username, password_hash, is_verified) 
                VALUES (?, ?, ?, ?, ?, 1)";
    }
    
    $stmt = $conn->prepare($sql);
    
    $stmt->execute([
        $data->name,
        $data->email,
        $profile,
        $data->username,
        $password_hash
    ]);

    $userId = $conn->lastInsertId();

    $emailEnviado = false;

    if ($emailFeaturesEnabled) {
        // --- GERAÇÃO DO TOKEN DE VERIFICAÇÃO ---
        $tokenPuro = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenPuro);
        $expiracao = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Salvar o token na nova tabela
        $sqlToken = "INSERT INTO email_verifications (user_id, email_to_verify, token_hash, type, expires_at) VALUES (?, ?, ?, 'REGISTER', ?)";
        $stmtToken = $conn->prepare($sqlToken);
        $stmtToken->execute([$userId, $data->email, $tokenHash, $expiracao]);

        // --- DISPARO DO E-MAIL ---
        $servicoEmail = new ServicoEmail();
        $emailEnviado = $servicoEmail->enviarEmailConfirmacao($data->email, $data->name, $tokenPuro);
    }

    // Confirmar as inserções no banco
    $conn->commit();

    http_response_code(201); // Created
    
    // Ajuste da mensagem de retorno para o frontend
    if ($emailFeaturesEnabled && $emailEnviado) {
        echo json_encode([
            'message' => 'Usuário cadastrado com sucesso! Verifique sua caixa de entrada para confirmar o e-mail.',
            'insertedId' => $userId
        ]);
    } elseif ($emailFeaturesEnabled && !$emailEnviado) {
        echo json_encode([
            'message' => 'Usuário cadastrado, mas houve um erro ao enviar o e-mail de confirmação.',
            'insertedId' => $userId
        ]);
    } else {
        echo json_encode([
            'message' => 'Usuário cadastrado com sucesso! Conta já ativada (verificação por e-mail desligada).',
            'insertedId' => $userId
        ]);
    }

} catch (PDOException $e) {
    // Se der qualquer erro no banco, a transação é desfeita (não salva usuário pela metade)
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(500);
    if ($e->getCode() == '23000') {
         echo json_encode(['error' => 'Erro: Email ou Nome de Usuário já existem.']);
    } else {
         echo json_encode(['error' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
    }
}
?>
<?php
// redefinir_senha_api.php - Efetiva a troca de senha a partir de um token de redefinição válido

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';

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

if (!isset($data->token) || empty(trim($data->token))) {
    http_response_code(400);
    echo json_encode(['error' => 'Token ausente.']);
    exit;
}

if (!isset($data->new_password) || !isset($data->confirm_password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nova senha e confirmação são obrigatórias.']);
    exit;
}

if (strlen($data->new_password) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Nova senha deve ter no mínimo 6 caracteres.']);
    exit;
}

if ($data->new_password !== $data->confirm_password) {
    http_response_code(400);
    echo json_encode(['error' => 'As senhas não coincidem.']);
    exit;
}

$tokenHash = hash('sha256', trim($data->token));

try {
    // Revalida o token no momento da escrita (defesa em profundidade — a
    // página redefinir_senha.php já validou antes de mostrar o formulário,
    // mas o token pode expirar/ser usado entre a exibição e o envio).
    $stmt = $conn->prepare("SELECT id, user_id, expires_at FROM email_verifications WHERE token_hash = ? AND type = 'PASSWORD_RESET' AND used = 0");
    $stmt->execute([$tokenHash]);
    $verificacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verificacao) {
        http_response_code(400);
        echo json_encode(['error' => 'Este link de redefinição não é válido ou já foi utilizado.']);
        exit;
    }

    if (strtotime($verificacao['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Este link de redefinição expirou. Solicite um novo.']);
        exit;
    }

    $conn->beginTransaction();

    $new_password_hash = password_hash($data->new_password, PASSWORD_BCRYPT);

    $stmtUpdate = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND deletedAt IS NULL");
    $stmtUpdate->execute([$new_password_hash, $verificacao['user_id']]);

    // Invalida o token usado e qualquer outro pedido de redefinição pendente
    // do mesmo usuário (evita que um link antigo, esquecido em algum e-mail,
    // continue válido depois que a senha já foi trocada).
    $stmtInvalidate = $conn->prepare("UPDATE email_verifications SET used = 1 WHERE user_id = ? AND type = 'PASSWORD_RESET' AND used = 0");
    $stmtInvalidate->execute([$verificacao['user_id']]);

    $conn->commit();

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Senha redefinida com sucesso.']);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    api_error_response('Erro ao redefinir senha', $e, 500);
}
?>

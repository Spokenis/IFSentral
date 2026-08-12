<?php
/**
 * API: Atualizar Perfil do Usuário
 * Permite atualizar nome, e-mail e username
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/ApiError.php';

header('Content-Type: application/json; charset=utf-8');
setupSecureCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    // Obter ID do usuário da sessão
    $user_id = $_SESSION['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não autenticado']);
        exit;
    }
    
    // Obter dados do corpo da requisição
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $username = trim($input['username'] ?? '');
    
    // Validações
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail é obrigatório']);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail inválido']);
        exit;
    }
    
    if (empty($username)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username é obrigatório']);
        exit;
    }
    
    if (strlen($username) < 3 || strlen($username) > 20) {
        http_response_code(400);
        echo json_encode(['error' => 'Username deve ter entre 3 e 20 caracteres']);
        exit;
    }
    
    // Verificar se e-mail já existe (exceto o do próprio usuário)
    $checkEmailSql = "SELECT id FROM users WHERE email = ? AND id != ? AND deletedAt IS NULL";
    $checkEmailStmt = $conn->prepare($checkEmailSql);
    $checkEmailStmt->execute([$email, $user_id]);
    
    if ($checkEmailStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Este e-mail já está em uso por outro usuário']);
        exit;
    }
    
    // Verificar se username já existe (exceto o do próprio usuário)
    $checkUsernameSql = "SELECT id FROM users WHERE username = ? AND id != ? AND deletedAt IS NULL";
    $checkUsernameStmt = $conn->prepare($checkUsernameSql);
    $checkUsernameStmt->execute([$username, $user_id]);
    
    if ($checkUsernameStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Este username já está em uso por outro usuário']);
        exit;
    }
    
    // Atualizar perfil
    $updateSql = "UPDATE users 
                  SET name = ?, email = ?, username = ? 
                  WHERE id = ? AND deletedAt IS NULL";
    
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$name, $email, $username, $user_id]);
    
    // Atualizar sessão com novo e-mail se necessário
    $_SESSION['user_email'] = $email;
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Perfil atualizado com sucesso'
    ]);
    
} catch (PDOException $e) {
    api_error_response('Erro ao atualizar perfil', $e, 500);
}

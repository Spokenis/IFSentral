<?php
/**
 * API: Atualizar Senha do Usuário
 * Permite alterar a senha após validar a senha atual
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

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
    
    $current_password = $input['current_password'] ?? '';
    $new_password = $input['new_password'] ?? '';
    $confirm_password = $input['confirm_password'] ?? '';
    
    // Validações
    if (empty($current_password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Senha atual é obrigatória']);
        exit;
    }
    
    if (empty($new_password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nova senha é obrigatória']);
        exit;
    }
    
    if (strlen($new_password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Nova senha deve ter no mínimo 6 caracteres']);
        exit;
    }
    
    if ($new_password !== $confirm_password) {
        http_response_code(400);
        echo json_encode(['error' => 'As senhas não coincidem']);
        exit;
    }
    
    // Buscar hash da senha atual do banco
    $getUserSql = "SELECT password_hash FROM users WHERE id = ? AND deletedAt IS NULL";
    $getUserStmt = $conn->prepare($getUserSql);
    $getUserStmt->execute([$user_id]);
    $user = $getUserStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }
    
    // Verificar se a senha atual está correta
    if (!password_verify($current_password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Senha atual incorreta']);
        exit;
    }
    
    // Gerar hash da nova senha
    $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
    
    // Atualizar senha no banco
    $updateSql = "UPDATE users 
                  SET password_hash = ? 
                  WHERE id = ? AND deletedAt IS NULL";
    
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$new_password_hash, $user_id]);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Senha atualizada com sucesso'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao atualizar senha: ' . $e->getMessage()]);
}

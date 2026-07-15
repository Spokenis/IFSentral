<?php
// api/admin_usuarios.php
require_once '../config/config.php';
setupSecureCORS();
header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';
require '../auth/auth_check.php';

// Verifica se é Admin
$identifier = $_SESSION['user_id'] ?? $_SESSION['email'];
$column = isset($_SESSION['user_id']) ? 'id' : 'email';

$stmt = $conn->prepare("SELECT id, profile FROM users WHERE $column = ? AND deletedAt IS NULL");
$stmt->execute([$identifier]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || $admin['profile'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado. Apenas administradores.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// LISTAR USUÁRIOS
if ($method === 'GET') {
    try {
        $stmt = $conn->query("SELECT id, name, email, profile, createdAt FROM users WHERE deletedAt IS NULL ORDER BY createdAt DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($users);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao listar usuários: ' . $e->getMessage()]);
    }
    exit;
}

// ATUALIZAR PERFIL (ROLE)
if ($method === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);
    $target_id = $data['user_id'] ?? null;
    $new_profile = $data['profile'] ?? null;
    
    if (!$target_id || !$new_profile || !in_array($new_profile, ['Admin', 'Moderator', 'User'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados inválidos para atualização.']);
        exit;
    }
    
    if ($target_id == $admin['id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Você não pode alterar seu próprio perfil por aqui.']);
        exit;
    }

    try {
        $stmt = $conn->prepare("UPDATE users SET profile = ? WHERE id = ? AND deletedAt IS NULL");
        $stmt->execute([$new_profile, $target_id]);
        echo json_encode(['success' => true, 'message' => 'Perfil atualizado com sucesso.']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar perfil.']);
    }
    exit;
}

// EXPULSAR / APAGAR USUÁRIO (SOFT DELETE)
if ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $target_id = $data['user_id'] ?? null;

    if (!$target_id || $target_id == $admin['id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Usuário inválido ou tentativa de auto-exclusão.']);
        exit;
    }

    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("UPDATE users SET deletedAt = NOW() WHERE id = ?");
        $stmt->execute([$target_id]);
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Usuário removido do sistema.']);
    } catch (PDOException $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao remover usuário.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
<?php
// enviar_convite.php - Send invitation to join a project

require_once '../config/config.php';
require_once '../core/AuthMiddleware.php';
setupSecureCORS();

use App\Core\AuthMiddleware;

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

// Validação
if (!isset($data->project_id) || !isset($data->invited_email) || !isset($data->role_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id, invited_email e role_id são obrigatórios.']);
    exit;
}

$user_id = AuthMiddleware::requireAuth();

$project_id = intval($data->project_id);
$invited_email = trim($data->invited_email);
$role_id = intval($data->role_id);

try {
    if (!AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você não tem permissão para convidar membros a este projeto.']);
        exit;
    }

    $profileSql = "SELECT profile FROM users WHERE id = ? AND deletedAt IS NULL LIMIT 1";
    $profileStmt = $conn->prepare($profileSql);
    $profileStmt->execute([$user_id]);
    $userProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userProfile) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado.']);
        exit;
    }

    // Verificar se o usuário é Moderator ou Admin no sistema (pode ser gerente)
    if ($userProfile['profile'] !== 'Moderator' && $userProfile['profile'] !== 'Admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas Moderadores podem gerenciar projetos.']);
        exit;
    }
    
    // Verificar se o email é válido
    if (!filter_var($invited_email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email inválido.']);
        exit;
    }

    // Verificar se a permissao existe
    $roleSql = "SELECT id FROM roles WHERE id = ?";
    $roleStmt = $conn->prepare($roleSql);
    $roleStmt->execute([$role_id]);
    if ($roleStmt->rowCount() == 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Permissão inválida.']);
        exit;
    }
    
    // Verificar se o email já é membro do projeto
    $checkMemberSql = "
        SELECT 1 FROM users_projects up
        JOIN users u ON up.user_id = u.id
        WHERE up.project_id = ? AND u.email = ?
    ";
    $checkMemberStmt = $conn->prepare($checkMemberSql);
    $checkMemberStmt->execute([$project_id, $invited_email]);
    if ($checkMemberStmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Este email já é membro do projeto.']);
        exit;
    }
    
    // Verificar se já existe convite pending para este email
    $checkInviteSql = "
        SELECT id FROM invitations
        WHERE project_id = ? AND invited_email = ? AND status = 'pending'
    ";
    $checkInviteStmt = $conn->prepare($checkInviteSql);
    $checkInviteStmt->execute([$project_id, $invited_email]);
    if ($checkInviteStmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Já existe um convite pendente para este email.']);
        exit;
    }
    
    // Procurar o usuário por email
    $findUserSql = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
    $findUserStmt = $conn->prepare($findUserSql);
    $findUserStmt->execute([$invited_email]);
    $invitedUser = $findUserStmt->fetch(PDO::FETCH_ASSOC);
    $invited_user_id = $invitedUser ? $invitedUser['id'] : null;
    
    // Criar convite
    $inviteSql = "
        INSERT INTO invitations (project_id, invited_by, invited_user_id, invited_email, role_id, expires_at)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
    ";
    $inviteStmt = $conn->prepare($inviteSql);
    $inviteStmt->execute([$project_id, $user_id, $invited_user_id, $invited_email, $role_id]);
    
    http_response_code(201);
    echo json_encode([
        'message' => 'Convite enviado com sucesso!',
        'invitation_id' => $conn->lastInsertId(),
        'invited_email' => $invited_email
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao enviar convite: ' . $e->getMessage()]);
}
?>

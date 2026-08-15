<?php
// enviar_convite.php - Send invitation to join a project

require_once '../config/config.php';
require_once '../core/AuthMiddleware.php';
setupSecureCORS();
require_once '../core/ApiError.php';
require_once '../core/Csrf.php';

use App\Core\AuthMiddleware;
use App\Core\Csrf;

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

Csrf::requireValidToken();

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
    // Apenas o Gerente do projeto pode convidar membros (mesma regra usada em
    // alterar_visibilidade_projeto.php, promover_gerente.php, expulsar_participante.php).
    // Antes havia uma checagem extra exigindo perfil global Moderator/Admin,
    // o que impedia um Gerente comum de convidar gente para o próprio projeto.
    if (!AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você não tem permissão para convidar membros a este projeto.']);
        exit;
    }

    // Verificar se o e-mail é válido
    if (!filter_var($invited_email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail inválido.']);
        exit;
    }

    // Verificar se a permissão existe
    $roleSql = "SELECT id FROM roles WHERE id = ?";
    $roleStmt = $conn->prepare($roleSql);
    $roleStmt->execute([$role_id]);
    if ($roleStmt->rowCount() == 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Permissão inválida.']);
        exit;
    }
    
    // Verificar se o e-mail já é membro do projeto
    $checkMemberSql = "
        SELECT 1 FROM users_projects up
        JOIN users u ON up.user_id = u.id
        WHERE up.project_id = ? AND u.email = ?
    ";
    $checkMemberStmt = $conn->prepare($checkMemberSql);
    $checkMemberStmt->execute([$project_id, $invited_email]);
    if ($checkMemberStmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Este e-mail já é membro do projeto.']);
        exit;
    }
    
    // Verificar se já existe convite pendente para este e-mail
    $checkInviteSql = "
        SELECT id FROM invitations
        WHERE project_id = ? AND invited_email = ? AND status = 'pending'
    ";
    $checkInviteStmt = $conn->prepare($checkInviteSql);
    $checkInviteStmt->execute([$project_id, $invited_email]);
    if ($checkInviteStmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Já existe um convite pendente para este e-mail.']);
        exit;
    }
    
    // Procurar o usuário por e-mail
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
    
    // Busca os dados humanizados para personalizar a mensagem
    $projSql = "SELECT p.name AS project_name, u.name AS sender_name 
                FROM projects p, users u 
                WHERE p.id = ? AND u.id = ?";
    $projStmt = $conn->prepare($projSql);
    $projStmt->execute([$project_id, $user_id]);
    $projData = $projStmt->fetch(PDO::FETCH_ASSOC);

    $emailEnviado = false;

    // Condiciona o envio à liberação no ambiente
    if (defined('ENABLE_EMAIL_FEATURES') && ENABLE_EMAIL_FEATURES === true) {
        require_once '../core/ServicoEmail.php';
        $servicoEmail = new ServicoEmail();
        $emailEnviado = $servicoEmail->enviarEmailConvite($invited_email, $projData['project_name'], $projData['sender_name']);
    }
    
    http_response_code(201);
    echo json_encode([
        'message' => 'Convite enviado com sucesso!',
        'invitation_id' => $conn->lastInsertId(),
        'invited_email' => $invited_email,
        'email_dispatched' => $emailEnviado
    ]);
    
} catch (PDOException $e) {
    api_error_response('Erro ao enviar convite', $e, 500);
}
?>

<?php
// recusar_convite.php - Reject an invitation

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->invitation_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'invitation_id é obrigatório.']);
    exit;
}

// Obter user_id da sessão
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id && isset($_SESSION['email'])) {
    try {
        $sql_user = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->execute([$_SESSION['email']]);
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $user_id = $user_data['id'];
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Erro ao validar usuário']);
        exit;
    }
}

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

$invitation_id = intval($data->invitation_id);

try {
    $conn->beginTransaction();
    
    // Buscar o convite
    $inviteSql = "
        SELECT id, invited_user_id, invited_email, status
        FROM invitations
        WHERE id = ? AND expires_at > NOW()
    ";
    $inviteStmt = $conn->prepare($inviteSql);
    $inviteStmt->execute([$invitation_id]);
    $invitation = $inviteStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invitation) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Convite não encontrado ou expirado.']);
        exit;
    }
    
    if ($invitation['status'] !== 'pending') {
        $conn->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Este convite já foi processado.']);
        exit;
    }
    
    // Verificar se o convite é para este usuário
    $isForThisUser = ($invitation['invited_user_id'] == $user_id) || 
                     ($invitation['invited_email'] == $_SESSION['email'] ?? '');
    
    if (!$isForThisUser) {
        $conn->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'Este convite não é para você.']);
        exit;
    }
    
    // Atualizar convite como recusado
    $rejectSql = "
        UPDATE invitations 
        SET status = 'rejected', invited_user_id = ?, rejected_at = NOW()
        WHERE id = ?
    ";
    $rejectStmt = $conn->prepare($rejectSql);
    $rejectStmt->execute([$user_id, $invitation_id]);
    
    $conn->commit();
    
    http_response_code(200);
    echo json_encode(['message' => 'Convite recusado.']);
    
} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao recusar convite: ' . $e->getMessage()]);
}
?>

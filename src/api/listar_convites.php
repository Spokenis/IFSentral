<?php
// listar_convites.php - List pending invitations for the logged-in user

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
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

try {
    // Buscar convites do usuário (por email ou por invited_user_id)
    $sql = "
        SELECT 
            i.id,
            i.project_id,
            i.invited_by,
            i.invited_email,
            i.role_id,
            i.status,
            i.created_at,
            i.expires_at,
            p.name as project_name,
            p.description as project_description,
            u_inviter.name as inviter_name,
            u_inviter.email as inviter_email,
            r.name as role_name
        FROM invitations i
        JOIN projects p ON i.project_id = p.id
        JOIN users u_inviter ON i.invited_by = u_inviter.id
        JOIN roles r ON i.role_id = r.id
        WHERE 
            (
                (i.invited_user_id = ? AND i.status = 'pending')
                OR (i.invited_email = ? AND i.status = 'pending')
            )
            AND i.expires_at > NOW()
        ORDER BY i.created_at DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id, $_SESSION['email'] ?? '']);
    $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode($invitations);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao listar convites: ' . $e->getMessage()]);
}
?>

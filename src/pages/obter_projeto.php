<?php
// obter_projeto.php - Retorna informações de um projeto específico com validação de permissão

require_once '../config/config.php';
require '../config/db.php';
require '../auth/auth_check.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do projeto é obrigatório']);
    exit;
}

$project_id = intval($_GET['id']);

// Obter user_id da sessão ou do banco de dados basado no email
$user_id_logado = $_SESSION['user_id'] ?? null;
if (!$user_id_logado && isset($_SESSION['email'])) {
    try {
        $sql_user = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->execute([$_SESSION['email']]);
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $user_id_logado = $user_data['id'];
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Erro ao validar usuário']);
        exit;
    }
}

if (!$user_id_logado) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

try {
    $sql = "
        SELECT 
            p.id, 
            p.name, 
            p.description,
            p.public,
            p.maxUsers,
            r.name AS user_role_name,
            
            (SELECT COUNT(*) FROM users_projects up_total WHERE up_total.project_id = p.id) AS participant_count,
            (SELECT COUNT(*) FROM devices d WHERE d.project_id = p.id AND d.deletedAt IS NULL) AS device_count,
            
            (
                SELECT GROUP_CONCAT(t.name) 
                FROM project_tags pt
                JOIN tags t ON pt.tag_id = t.id
                WHERE pt.project_id = p.id
            ) AS project_tags
            
        FROM 
            users_projects up_user
        JOIN 
            projects p ON up_user.project_id = p.id
        JOIN 
            roles r ON up_user.role_id = r.id
        WHERE 
            up_user.user_id = ? AND p.id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id_logado, $project_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada ou projeto não encontrado']);
        exit;
    }

    http_response_code(200);
    echo json_encode($project);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao obter projeto: ' . $e->getMessage()]);
}
?>

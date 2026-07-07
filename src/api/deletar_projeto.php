<?php
// deletar_projeto.php - Delete a project (Only Manager can delete)

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

if ($_SERVER['REQUEST_METHOD'] != 'DELETE' && $_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use DELETE ou POST.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->project_id) || !is_numeric($data->project_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id é obrigatório.']);
    exit;
}

$user_id = AuthMiddleware::requireAuth();

$project_id = intval($data->project_id);

try {
    $conn->beginTransaction();
    
    if (!AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        $conn->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'Você não tem permissão para deletar este projeto.']);
        exit;
    }

    // Regra de negócio adicional: apenas Moderator/Admin do sistema
    $profileSql = "SELECT profile FROM users WHERE id = ? AND deletedAt IS NULL LIMIT 1";
    $profileStmt = $conn->prepare($profileSql);
    $profileStmt->execute([$user_id]);
    $userProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userProfile) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }

    // Verificar se o usuário é Moderator ou Admin no sistema
    if ($userProfile['profile'] !== 'Moderator' && $userProfile['profile'] !== 'Admin') {
        $conn->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'Apenas Moderadores podem gerenciar projetos.']);
        exit;
    }
    
    // Soft delete: marcar como deletado
    $deleteSql = "UPDATE projects SET deletedAt = NOW() WHERE id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$project_id]);
    
    $conn->commit();
    
    http_response_code(200);
    echo json_encode(['message' => 'Projeto deletado com sucesso!']);
    
} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao deletar projeto: ' . $e->getMessage()]);
}
?>

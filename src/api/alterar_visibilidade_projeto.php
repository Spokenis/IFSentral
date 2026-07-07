<?php
/**
 * API: Alterar Visibilidade do Projeto (Público/Privado)
 * 
 * POST /api/alterar_visibilidade_projeto.php
 * {
 *   "project_id": 1,
 *   "public": 1 ou 0
 * }
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/AuthMiddleware.php';
setupSecureCORS();

use App\Core\AuthMiddleware;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['project_id']) || !isset($input['public'])) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id e public são obrigatórios']);
        exit;
    }

    $project_id = intval($input['project_id']);
    $is_public = intval($input['public']) > 0 ? 1 : 0;
    $user_id = AuthMiddleware::requireAuth();

    // Verificar se o projeto existe
    $projectSql = "SELECT id, name FROM projects WHERE id = ? AND deletedAt IS NULL";
    $projectStmt = $conn->prepare($projectSql);
    $projectStmt->execute([$project_id]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => 'Projeto não encontrado']);
        exit;
    }

    if (!AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas o gerente do projeto pode alterar a visibilidade']);
        exit;
    }

    // Atualizar visibilidade do projeto
    $updateSql = "UPDATE projects SET `public` = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$is_public, $project_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $is_public ? 'Projeto tornado público' : 'Projeto tornado privado',
        'public' => $is_public
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao atualizar projeto: ' . $e->getMessage()]);
}
?>

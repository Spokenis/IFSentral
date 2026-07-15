<?php
/**
 * API: Expulsar Participante do Projeto
 * 
 * POST /api/expulsar_participante.php
 * {
 *   "project_id": 1,
 *   "user_id": 2
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

    if (!isset($input['project_id']) || !isset($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id e user_id são obrigatórios']);
        exit;
    }

    $project_id = intval($input['project_id']);
    $user_to_remove = intval($input['user_id']);
    $user_id = AuthMiddleware::requireAuth();

    // Não permitir remover a si mesmo
    if ($user_to_remove == $user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Use a opção "Sair do projeto" para sair você mesmo.']);
        exit;
    }

    if (!AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas gerentes podem expulsar participantes']);
        exit;
    }

    // Verificar se o usuário a ser removido é gerente
    $targetSql = "SELECT up.id, r.name FROM users_projects up
                  JOIN roles r ON up.role_id = r.id
                  WHERE up.project_id = ? AND up.user_id = ?";
    $targetStmt = $conn->prepare($targetSql);
    $targetStmt->execute([$project_id, $user_to_remove]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Participante não encontrado']);
        exit;
    }

    // Não permitir remover gerente (apenas entre gerentes)
    if ($target['name'] === 'Gerente') {
        http_response_code(403);
        echo json_encode(['error' => 'Você não pode expulsar outro gerente. Promova um participante a gerente primeiro.']);
        exit;
    }

    // Remover participante
    $deleteSql = "DELETE FROM users_projects WHERE id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$target['id']]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Participante expulso com sucesso.'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao expulsar participante: ' . $e->getMessage()]);
}
?>

<?php
/**
 * API: Sair do Projeto
 * 
 * POST /api/sair_projeto.php
 * {
 *   "project_id": 1
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

    if (!isset($input['project_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id é obrigatório']);
        exit;
    }

    $project_id = intval($input['project_id']);
    $user_id = AuthMiddleware::requireAuth();

    if (!AuthMiddleware::hasProjectAccess($conn, $user_id, $project_id)) {
        http_response_code(404);
        echo json_encode(['error' => 'Você não é participante deste projeto']);
        exit;
    }

    // Verificar se o usuário é participante do projeto
    $checkSql = "SELECT up.id, r.name FROM users_projects up
                 JOIN roles r ON up.role_id = r.id
                 WHERE up.project_id = ? AND up.user_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$project_id, $user_id]);
    $participation = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$participation) {
        http_response_code(404);
        echo json_encode(['error' => 'Você não é participante deste projeto']);
        exit;
    }

    // Se é gerente, verificar se há outro gerente no projeto
    if ($participation['name'] === 'Gerente') {
        $countGerentesSql = "SELECT COUNT(*) as count FROM users_projects up
                             JOIN roles r ON up.role_id = r.id
                             WHERE up.project_id = ? AND r.name = 'Gerente'";
        $countGerentesStmt = $conn->prepare($countGerentesSql);
        $countGerentesStmt->execute([$project_id]);
        $countGerentesResult = $countGerentesStmt->fetch(PDO::FETCH_ASSOC);

        if ($countGerentesResult['count'] == 1) {
            http_response_code(409);
            echo json_encode(['error' => 'Você é o único gerente do projeto. Promova outro participante a gerente antes de sair.']);
            exit;
        }
    }

    // Remover usuário do projeto
    $deleteSql = "DELETE FROM users_projects WHERE id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$participation['id']]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Você saiu do projeto com sucesso.'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao sair do projeto: ' . $e->getMessage()]);
}
?>

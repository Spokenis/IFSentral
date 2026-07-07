<?php
/**
 * API: Promover Participante a Gerente
 * 
 * POST /api/promover_gerente.php
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
    $user_to_promote = intval($input['user_id']);
    $user_id = AuthMiddleware::requireAuth();

    if (!AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas gerentes podem promover participantes']);
        exit;
    }

    // Verificar se o usuário a ser promovido é participante
    $targetSql = "SELECT up.id, r.id as role_id, r.name FROM users_projects up
                  JOIN roles r ON up.role_id = r.id
                  WHERE up.project_id = ? AND up.user_id = ?";
    $targetStmt = $conn->prepare($targetSql);
    $targetStmt->execute([$project_id, $user_to_promote]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Participante não encontrado']);
        exit;
    }

    // Se já é gerente, erro
    if ($target['name'] === 'Gerente') {
        http_response_code(409);
        echo json_encode(['error' => 'Este participante já é gerente']);
        exit;
    }

    // Obter role_id de "Gerente"
    $roleGermenteSql = "SELECT id FROM roles WHERE name = 'Gerente' LIMIT 1";
    $roleGerenteStmt = $conn->prepare($roleGermenteSql);
    $roleGerenteStmt->execute();
    $roleGerente = $roleGerenteStmt->fetch(PDO::FETCH_ASSOC);

    if (!$roleGerente) {
        http_response_code(500);
        echo json_encode(['error' => 'Role "Gerente" não encontrada']);
        exit;
    }

    // Promover participante
    $updateSql = "UPDATE users_projects SET role_id = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$roleGerente['id'], $target['id']]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Participante promovido a gerente com sucesso.'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao promover participante: ' . $e->getMessage()]);
}
?>

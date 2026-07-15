<?php
/**
 * API: Responder Solicitação de Participação
 * 
 * POST /api/responder_solicitacao_participacao.php
 * {
 *   "request_id": 1,
 *   "action": "aceitar" ou "recusar"
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

    if (!isset($input['request_id']) || !isset($input['action'])) {
        http_response_code(400);
        echo json_encode(['error' => 'request_id e action são obrigatórios']);
        exit;
    }

    $request_id = intval($input['request_id']);
    $action = $input['action']; // 'aceitar' ou 'recusar'
    $user_id = AuthMiddleware::requireAuth();

    if (!in_array($action, ['aceitar', 'recusar'])) {
        http_response_code(400);
        echo json_encode(['error' => "Action deve ser 'aceitar' ou 'recusar'"]);
        exit;
    }

    // Buscar solicitação
    $requestSql = "SELECT * FROM project_join_requests WHERE id = ?";
    $requestStmt = $conn->prepare($requestSql);
    $requestStmt->execute([$request_id]);
    $joinRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$joinRequest) {
        http_response_code(404);
        echo json_encode(['error' => 'Solicitação não encontrada']);
        exit;
    }

    // Se já foi respondida, erro
    if ($joinRequest['status'] !== 'pendente') {
        http_response_code(409);
        echo json_encode(['error' => 'Esta solicitação já foi respondida']);
        exit;
    }

    if (!AuthMiddleware::isProjectManager($conn, $user_id, intval($joinRequest['project_id']))) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas o gerente pode responder solicitações']);
        exit;
    }

    // Se é aceitar, adicionar usuário ao projeto
    if ($action === 'aceitar') {
        // Verificar se o projeto ainda tem espaço
        $projectSql = "SELECT maxUsers FROM projects WHERE id = ?";
        $projectStmt = $conn->prepare($projectSql);
        $projectStmt->execute([$joinRequest['project_id']]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

        if ($project['maxUsers']) {
            $countSql = "SELECT COUNT(*) as count FROM users_projects WHERE project_id = ?";
            $countStmt = $conn->prepare($countSql);
            $countStmt->execute([$joinRequest['project_id']]);
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);

            if ($countResult['count'] >= $project['maxUsers']) {
                http_response_code(409);
                echo json_encode(['error' => 'O projeto não tem mais espaço para novos participantes']);
                exit;
            }
        }

        // Obter role_id de "Participante"
        $roleSql = "SELECT id FROM roles WHERE name = 'Participante' LIMIT 1";
        $roleStmt = $conn->prepare($roleSql);
        $roleStmt->execute();
        $role = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            http_response_code(500);
            echo json_encode(['error' => 'Role "Participante" não encontrada']);
            exit;
        }

        // Adicionar usuário ao projeto
        $addSql = "INSERT INTO users_projects (user_id, project_id, role_id) 
                   VALUES (?, ?, ?)";
        $addStmt = $conn->prepare($addSql);
        $addStmt->execute([$joinRequest['user_id'], $joinRequest['project_id'], $role['id']]);
    }

    // Atualizar status da solicitação
    $newStatus = $action === 'aceitar' ? 'aceito' : 'rejeitado';
    $updateSql = "UPDATE project_join_requests SET status = ?, respondedAt = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$newStatus, $request_id]);

    $message = $action === 'aceitar' 
        ? 'Solicitação aceita! O usuário agora é um participante do projeto.'
        : 'Solicitação recusada.';

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'status' => $newStatus
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao responder solicitação: ' . $e->getMessage()]);
}
?>

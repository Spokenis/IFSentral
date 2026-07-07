<?php
/**
 * API: Enviar Solicitação de Participação no Projeto
 * 
 * POST /api/enviar_solicitacao_participacao.php
 * {
 *   "project_id": 1,
 *   "message": "Gostaria de participar deste projeto"
 * }
 */

require_once __DIR__ . '/../config/config.php';
setupSecureCORS();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/auth_check.php';

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
    $message = $input['message'] ?? 'Gostaria de participar deste projeto';
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não autenticado']);
        exit;
    }

    // Verificar se o projeto existe e é público
    $projectSql = "SELECT id, public, maxUsers FROM projects WHERE id = ? AND deletedAt IS NULL";
    $projectStmt = $conn->prepare($projectSql);
    $projectStmt->execute([$project_id]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => 'Projeto não encontrado']);
        exit;
    }

    if (!$project['public']) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas projetos públicos aceitam solicitações']);
        exit;
    }

    // Verificar se o usuário já é participante
    $checkSql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$project_id, $user_id]);

    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Você já é participante deste projeto']);
        exit;
    }

    // Verificar se já existe uma solicitação pendente
    $existingSql = "SELECT id FROM project_join_requests 
                    WHERE project_id = ? AND user_id = ? AND status = 'pendente'";
    $existingStmt = $conn->prepare($existingSql);
    $existingStmt->execute([$project_id, $user_id]);

    if ($existingStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Você já enviou uma solicitação para este projeto']);
        exit;
    }

    // Verificar se o projeto tem espaço
    if ($project['maxUsers']) {
        $countSql = "SELECT COUNT(*) as count FROM users_projects WHERE project_id = ?";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute([$project_id]);
        $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);

        if ($countResult['count'] >= $project['maxUsers']) {
            http_response_code(409);
            echo json_encode(['error' => 'O projeto já atingiu o número máximo de participantes']);
            exit;
        }
    }

    // Criar solicitação
    $insertSql = "INSERT INTO project_join_requests (project_id, user_id, message, status) 
                  VALUES (?, ?, ?, 'pendente')";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->execute([$project_id, $user_id, $message]);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Solicitação enviada com sucesso! Aguarde a aprovação do gerente.',
        'request_id' => $conn->lastInsertId()
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao enviar solicitação: ' . $e->getMessage()]);
}
?>

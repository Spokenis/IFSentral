<?php
/**
 * API: Alternar Visibilidade do Gráfico (Público/Privado)
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

    if (!isset($input['chart_id']) || !isset($input['is_public'])) {
        http_response_code(400);
        echo json_encode(['error' => 'chart_id e is_public são obrigatórios']);
        exit;
    }

    $chart_id = intval($input['chart_id']);
    $is_public = intval($input['is_public']) > 0 ? 1 : 0;
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não autenticado']);
        exit;
    }

    // Buscar o chart e verificar se o usuário é gerente do projeto
    $chartSql = "SELECT c.project_id FROM charts c WHERE c.id = ?";
    $chartStmt = $conn->prepare($chartSql);
    $chartStmt->execute([$chart_id]);
    $chart = $chartStmt->fetch(PDO::FETCH_ASSOC);

    if (!$chart) {
        http_response_code(404);
        echo json_encode(['error' => 'Gráfico não encontrado']);
        exit;
    }

    // Validar se o usuário é Gerente do projeto
    $authSql = "
        SELECT 1 FROM users_projects up
        JOIN roles r ON up.role_id = r.id
        WHERE up.project_id = ? AND up.user_id = ? AND r.name = 'Gerente'
    ";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$chart['project_id'], $user_id]);

    if (!$authStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas o gerente do projeto pode alterar a visibilidade']);
        exit;
    }

    // Atualizar is_public
    $updateSql = "UPDATE charts SET is_public = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$is_public, $chart_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $is_public ? 'Gráfico tornado público' : 'Gráfico tornado privado',
        'is_public' => $is_public
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao atualizar gráfico: ' . $e->getMessage()]);
}

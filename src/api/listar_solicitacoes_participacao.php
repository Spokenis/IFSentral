<?php
/**
 * API: Listar Solicitações de Participação no Projeto
 * 
 * GET /api/listar_solicitacoes_participacao.php?project_id=1
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    if (!isset($_GET['project_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id é obrigatório']);
        exit;
    }

    $project_id = intval($_GET['project_id']);
    $status = $_GET['status'] ?? 'pendente'; // pendente, aceito, rejeitado ou todos
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não autenticado']);
        exit;
    }

    // Verificar se o usuário é gerente do projeto
    $authSql = "
        SELECT 1 FROM users_projects up
        JOIN roles r ON up.role_id = r.id
        WHERE up.project_id = ? AND up.user_id = ? AND r.name = 'Gerente'
    ";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$project_id, $user_id]);

    if (!$authStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas o gerente pode listar solicitações']);
        exit;
    }

    // Buscar solicitações
    $sql = "
        SELECT 
            pjr.id,
            pjr.project_id,
            pjr.user_id,
            pjr.status,
            pjr.message,
            pjr.createdAt,
            pjr.respondedAt,
            u.name as user_name,
            u.email as user_email,
            u.username as user_username
        FROM project_join_requests pjr
        LEFT JOIN users u ON pjr.user_id = u.id
        WHERE pjr.project_id = ?
    ";

    $params = [$project_id];

    if ($status !== 'todos') {
        $sql .= " AND pjr.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY pjr.createdAt DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'requests' => $requests,
        'total' => count($requests),
        'filter' => $status
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao listar solicitações: ' . $e->getMessage()]);
}
?>

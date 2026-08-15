<?php
// obter_stats_payloads.php

require_once '../config/config.php';
setupSecureCORS();
require_once __DIR__ . '/../core/ApiError.php';

header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';
require '../auth/auth_check.php'; // Garante que só usuários logados possam ver

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id é obrigatório e deve ser numérico.']);
    exit;
}
$project_id = intval($_GET['project_id']);

// Obter user_id da sessão ou do banco de dados baseado no e-mail
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id && isset($_SESSION['email'])) {
    try {
        $sql_user = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->execute([$_SESSION['email']]);
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $user_id = $user_data['id'];
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Erro ao validar usuário']);
        exit;
    }
}

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

try {
    // BUG FIX: Validar se o usuário tem acesso ao projeto
    $authSql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ?";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$project_id, $user_id]);
    if ($authStmt->rowCount() == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada para ver estatísticas deste projeto.']);
        exit;
    }
    
    // Duas queries separadas em vez de uma só sem filtro de data: a original
    // fazia COUNT(CASE WHEN DATE(p.created_at) = CURDATE() ...) sem NENHUM
    // filtro no WHERE, então escaneava o histórico inteiro de payloads do
    // projeto a cada carregamento da página — e piora conforme os dados
    // crescem. "Leituras hoje" agora tem um WHERE sargable (usa o índice
    // idx_device_time via device_id + created_at >= CURDATE()).
    $countSql = "
        SELECT COUNT(*) AS leituras_hoje
        FROM device_payloads p
        JOIN devices d ON p.device_id = d.id
        WHERE d.project_id = ? AND p.created_at >= CURDATE()
    ";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute([$project_id]);
    $leituras_hoje = (int) $countStmt->fetchColumn();

    // "Última leitura" continua precisando olhar todo o histórico (não tem
    // como saber o máximo sem isso), mas agora é só esse único agregado,
    // sem o CASE por linha somado a uma contagem sem filtro nenhum.
    $maxSql = "
        SELECT MAX(p.created_at) AS ultima_leitura
        FROM device_payloads p
        JOIN devices d ON p.device_id = d.id
        WHERE d.project_id = ?
    ";
    $maxStmt = $conn->prepare($maxSql);
    $maxStmt->execute([$project_id]);
    $ultima_leitura = $maxStmt->fetchColumn();

    $result = [
        'leituras_hoje' => $leituras_hoje,
        'ultima_leitura' => $ultima_leitura ?: 'Nenhuma',
    ];

    echo json_encode($result);

} catch (PDOException $e) {
    api_error_response('Erro no banco', $e, 500);
}
?>
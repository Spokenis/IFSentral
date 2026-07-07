<?php
// obter_stats_payloads.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';
require '../auth/auth_check.php'; // Garante que só usuários logados possam ver

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id é obrigatório e deve ser numérico.']);
    exit;
}
$project_id = intval($_GET['project_id']);

// Obter user_id da sessão ou do banco de dados basado no email
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
    
    // Esta consulta junta os payloads com os dispositivos
    // para filtrar por project_id
    $sql = "
        SELECT 
            -- Conta apenas os payloads criados hoje (CURDATE())
            COUNT(CASE WHEN DATE(p.created_at) = CURDATE() THEN 1 ELSE NULL END) AS leituras_hoje,
            -- Pega a data mais recente de payload
            MAX(p.created_at) AS ultima_leitura
        FROM 
            device_payloads p
        JOIN 
            devices d ON p.device_id = d.id
        WHERE 
            d.project_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Se nunca houve leitura, 'ultima_leitura' será NULL
    if ($result['ultima_leitura'] === null) {
        $result['ultima_leitura'] = "Nenhuma";
    }
    
    echo json_encode($result); 

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco: ' . $e->getMessage()]);
}
?>
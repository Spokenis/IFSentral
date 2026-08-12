<?php
// deletar_grafico.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php'; // Protegido

// Aceita JSON
$data = json_decode(file_get_contents("php://input"));

if (!isset($data->chart_id) || !is_numeric($data->chart_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'chart_id é obrigatório.']);
    exit;
}

$chart_id = $data->chart_id;

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
    // Verificação de Segurança: O usuário logado é dono deste projeto?
    $authSql = "
        SELECT 1 
        FROM charts c
        JOIN users_projects up ON c.project_id = up.project_id
        WHERE c.id = ? AND up.user_id = ?
    ";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$chart_id, $user_id]);
    
    if ($authStmt->rowCount() == 0) {
        http_response_code(403); // Forbidden
        echo json_encode(['error' => 'Permissão negada para apagar este gráfico.']);
        exit;
    }

    // Segurança OK, pode apagar
    $sql = "DELETE FROM charts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$chart_id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['message' => 'Gráfico apagado com sucesso.']);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Gráfico não encontrado.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco: ' . $e->getMessage()]);
}
?>
<?php
// atualizar_grafico.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php'; // Protegido

$data = json_decode(file_get_contents("php://input"));

// Validação
if (
    !isset($data->chart_id) ||
    !is_numeric($data->chart_id) ||
    !isset($data->name) ||
    !isset($data->chart_type) ||
    !isset($data->device_id) ||
    !is_numeric($data->device_id) ||
    !isset($data->json_key)
) {
    http_response_code(400);
    echo json_encode(['error' => 'Todos os campos são obrigatórios e device_id/chart_id devem ser numéricos.']);
    exit;
}

$chart_id = intval($data->chart_id); // BUG FIX: Converter para int
$user_id = $_SESSION['user_id'];

try {
    // Verificação de Segurança (igual ao deletar)
    $authSql = "
        SELECT 1 
        FROM charts c
        JOIN users_projects up ON c.project_id = up.project_id
        WHERE c.id = ? AND up.user_id = ?
    ";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$chart_id, $user_id]);
    
    if ($authStmt->rowCount() == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada para editar este gráfico.']);
        exit;
    }

    // Segurança OK, pode atualizar
    $sql = "UPDATE charts SET 
                name = ?, 
                chart_type = ?, 
                device_id = ?, 
                json_key = ?
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    
    $stmt->execute([
        $data->name,
        $data->chart_type,
        intval($data->device_id), // BUG FIX: Converter para int
        $data->json_key,
        $chart_id
    ]);

    http_response_code(200);
    echo json_encode(['message' => 'Gráfico atualizado com sucesso!']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
}
?>
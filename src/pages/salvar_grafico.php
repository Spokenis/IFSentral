<?php
// salvar_grafico.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php'; // Protegido

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

// Validação
if (
    !isset($data->project_id) ||
    !isset($data->name) ||
    !isset($data->chart_type) ||
    !isset($data->device_id) ||
    !isset($data->json_key)
) {
    http_response_code(400);
    echo json_encode(['error' => 'Campos obrigatórios: project_id, name, chart_type, device_id, json_key.']);
    exit;
}

try {
    // Insere na tabela 'charts'
    $sql = "INSERT INTO charts (project_id, name, chart_type, device_id, json_key) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    
    $stmt->execute([
        $data->project_id,
        $data->name,
        $data->chart_type,
        $data->device_id,
        $data->json_key
    ]);

    http_response_code(201); // Created
    echo json_encode([
        'message' => 'Gráfico salvo com sucesso!',
        'insertedId' => $conn->lastInsertId()
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
}
?>
<?php
// salvar_grafico.php

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/Csrf.php';

use App\Core\Csrf;

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

Csrf::requireValidToken();

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
    $user_id = $_SESSION['user_id'];

    // Validar se o usuário é membro do projeto
    $authStmt = $conn->prepare("SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ?");
    $authStmt->execute([$data->project_id, $user_id]);
    if ($authStmt->fetch() === false) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada. Você não é membro deste projeto.']);
        exit;
    }

    // Validar que o dispositivo pertence a este projeto (evita apontar o
    // gráfico para device_id de outro projeto)
    $deviceStmt = $conn->prepare("SELECT 1 FROM devices WHERE id = ? AND project_id = ? AND deletedAt IS NULL");
    $deviceStmt->execute([$data->device_id, $data->project_id]);
    if ($deviceStmt->fetch() === false) {
        http_response_code(403);
        echo json_encode(['error' => 'Dispositivo não pertence a este projeto.']);
        exit;
    }

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
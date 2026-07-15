<?php
// cadastrar_usuario.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

require '../config/db.php';

$data = json_decode(file_get_contents("php://input"));

if (
    !isset($data->name) ||
    !isset($data->email) ||
    !isset($data->username) ||
    !isset($data->password) ||
    empty($data->password)
) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Campos obrigatórios: name, email, username, e password.']);
    exit;
}

$profile = isset($data->profile) ? $data->profile : 'User';

// --- HASH DA SENHA ---
$password_hash = password_hash($data->password, PASSWORD_BCRYPT);

try {
    $sql = "INSERT INTO users (name, email, profile, username, password_hash) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    
    $stmt->execute([
        $data->name,
        $data->email,
        $profile,
        $data->username,
        $password_hash
    ]);

    http_response_code(201); // Created
    echo json_encode([
        'message' => 'Usuário cadastrado com sucesso!',
        'insertedId' => $conn->lastInsertId()
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    if ($e->getCode() == '23000') {
         echo json_encode(['error' => 'Erro: Email ou Nome de Usuário já existem.']);
    } else {
         echo json_encode(['error' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
    }
}
?>
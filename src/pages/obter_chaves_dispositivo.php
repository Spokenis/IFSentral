<?php
// obter_chaves_dispositivo.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';
require '../auth/auth_check.php'; // Protegido

if (!isset($_GET['device_id']) || !is_numeric($_GET['device_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'device_id é obrigatório.']);
    exit;
}
$device_id = intval($_GET['device_id']);

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
    // Validação de segurança: verifica se o usuário tem acesso ao dispositivo
    $authSql = "SELECT 1 FROM devices d JOIN users_projects up ON d.project_id = up.project_id WHERE d.id = ? AND up.user_id = ?";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$device_id, $user_id]);
    if ($authStmt->rowCount() == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada.']);
        exit;
    }
    
    // Pega TODOS os payloads do dispositivo (não apenas o último)
    $sql_payload = "SELECT payload FROM device_payloads WHERE device_id = ? ORDER BY id ASC";
    $stmt_payload = $conn->prepare($sql_payload);
    $stmt_payload->execute([$device_id]);
    $payloads = $stmt_payload->fetchAll(PDO::FETCH_COLUMN);

    if (empty($payloads)) {
        echo json_encode([]); // Nenhuma chave encontrada
        exit;
    }
    
    // Coleta todas as chaves únicas de todos os payloads
    $all_keys = [];
    foreach ($payloads as $payload_json) {
        $payload_data = json_decode($payload_json, true);
        if (is_array($payload_data)) {
            $keys = array_keys($payload_data);
            $all_keys = array_merge($all_keys, $keys);
        }
    }
    
    // Remove duplicatas e ordena
    $unique_keys = array_values(array_unique($all_keys));
    sort($unique_keys);
    
    echo json_encode($unique_keys); // Retorna todas as variáveis únicas

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar chaves: ' . $e->getMessage()]);
}
?>
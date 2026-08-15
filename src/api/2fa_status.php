<?php
// 2fa_status.php - Retorna se o 2FA está ativado para o usuário logado

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;

try {
    $stmt = $conn->prepare("SELECT enabled FROM user_2fa WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['enabled' => $row ? (bool)$row['enabled'] : false]);

} catch (PDOException $e) {
    api_error_response('Erro ao verificar status do 2FA', $e, 500);
}
?>

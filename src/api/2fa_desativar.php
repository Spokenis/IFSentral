<?php
// 2fa_desativar.php - Desativa o 2FA (exige a senha atual como confirmação)

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';
require_once '../core/Csrf.php';

use App\Core\Csrf;

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

Csrf::requireValidToken();

$user_id = $_SESSION['user_id'] ?? null;
$data = json_decode(file_get_contents("php://input"));

if (!isset($data->password) || empty($data->password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe sua senha atual para desativar o 2FA.']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? AND deletedAt IS NULL");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($data->password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Senha incorreta.']);
        exit;
    }

    $conn->beginTransaction();

    $del1 = $conn->prepare("DELETE FROM user_2fa WHERE user_id = ?");
    $del1->execute([$user_id]);

    $del2 = $conn->prepare("DELETE FROM user_2fa_backup_codes WHERE user_id = ?");
    $del2->execute([$user_id]);

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Autenticação de dois fatores desativada.']);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    api_error_response('Erro ao desativar 2FA', $e, 500);
}
?>

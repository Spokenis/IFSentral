<?php
// 2fa_confirmar_configuracao.php - Confirma o código e ativa o 2FA definitivamente

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';
require_once '../core/Csrf.php';
require_once '../core/TwoFactorAuth.php';

use App\Core\Csrf;
use App\Core\TwoFactorAuth;

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

if (empty($_SESSION['pending_2fa_secret'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhuma configuração de 2FA em andamento. Inicie o processo novamente.']);
    exit;
}

if (!isset($data->code) || empty(trim($data->code))) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe o código de 6 dígitos do seu aplicativo autenticador.']);
    exit;
}

$secret = $_SESSION['pending_2fa_secret'];
$step = TwoFactorAuth::verifyCode($secret, $data->code);

if ($step === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Código inválido. Verifique o horário do seu dispositivo e tente novamente.']);
    exit;
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO user_2fa (user_id, secret, enabled, last_used_step)
        VALUES (?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 1, last_used_step = VALUES(last_used_step)
    ");
    $stmt->execute([$user_id, $secret, $step]);

    // Remove códigos de backup antigos (de uma configuração anterior, se houver)
    // e gera um novo conjunto — os antigos deixam de ser válidos.
    $del = $conn->prepare("DELETE FROM user_2fa_backup_codes WHERE user_id = ?");
    $del->execute([$user_id]);

    $backupCodes = TwoFactorAuth::generateBackupCodes(8);
    $insertCode = $conn->prepare("INSERT INTO user_2fa_backup_codes (user_id, code_hash) VALUES (?, ?)");
    foreach ($backupCodes as $code) {
        $insertCode->execute([$user_id, password_hash($code, PASSWORD_BCRYPT)]);
    }

    $conn->commit();

    unset($_SESSION['pending_2fa_secret']);

    echo json_encode([
        'success' => true,
        'message' => 'Autenticação de dois fatores ativada com sucesso.',
        'backup_codes' => $backupCodes,
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    api_error_response('Erro ao confirmar configuração do 2FA', $e, 500);
}
?>

<?php
// 2fa_iniciar_configuracao.php - Gera um novo segredo TOTP pendente de confirmação

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
$user_email = $_SESSION['email'] ?? '';

try {
    $stmt = $conn->prepare("SELECT enabled FROM user_2fa WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && $existing['enabled']) {
        http_response_code(400);
        echo json_encode(['error' => 'A autenticação de dois fatores já está ativada. Desative-a antes de reconfigurar.']);
        exit;
    }

    // Segredo fica só na sessão até ser confirmado com um código válido —
    // evita gravar segredos "órfãos" no banco para configurações abandonadas.
    $secret = TwoFactorAuth::generateSecret();
    $_SESSION['pending_2fa_secret'] = $secret;

    echo json_encode([
        'secret' => $secret,
        'otpauth_uri' => TwoFactorAuth::getOtpAuthUri($secret, $user_email),
    ]);

} catch (PDOException $e) {
    api_error_response('Erro ao iniciar configuração do 2FA', $e, 500);
}
?>

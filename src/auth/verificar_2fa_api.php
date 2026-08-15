<?php
// verificar_2fa_api.php - Segunda etapa do login: valida o código TOTP (ou um
// código de backup) e só então conclui a sessão autenticada.

require_once '../config/config.php';
require_once '../core/ApiError.php';
require_once '../core/RateLimiter.php';
require_once '../core/Csrf.php';
require_once '../core/TwoFactorAuth.php';

use App\Core\RateLimiter;
use App\Core\Csrf;
use App\Core\TwoFactorAuth;

setupSecureCORS();

// Resume a sessão iniciada em login_api.php (que já validou a senha).
// Lifetime 0 aqui é só o valor inicial — o cookie "lembrar-me" é reemitido
// explicitamente mais abaixo, com base no que foi decidido no passo 1.
setupSecureSession(0);

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

if (empty($_SESSION['2fa_pending_user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhum login em andamento. Faça login novamente.']);
    exit;
}

$pending_user_id = $_SESSION['2fa_pending_user_id'];
$remember = !empty($_SESSION['2fa_pending_remember']);
$session_lifetime = $remember ? 60 * 60 * 24 * 30 : 0;

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->code) || empty(trim($data->code))) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe o código de autenticação.']);
    exit;
}

$codeInput = trim($data->code);
$client_ip = $_SERVER['REMOTE_ADDR'] ?? null;

// Mesmo mecanismo de rate limiting do login — essencial aqui, já que um
// código de 6 dígitos tem espaço de busca muito menor que uma senha.
$rateLimiter = new RateLimiter($conn);
$rateCheck = $rateLimiter->checkLoginAttempts('2fa:' . $pending_user_id, $client_ip);

if (!$rateCheck['allowed']) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitas tentativas. Tente novamente mais tarde.']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT secret, last_used_step FROM user_2fa WHERE user_id = ? AND enabled = 1");
    $stmt->execute([$pending_user_id]);
    $twofa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$twofa) {
        // 2FA foi desativado entre o passo 1 e agora — não há o que verificar.
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_remember']);
        http_response_code(400);
        echo json_encode(['error' => 'A autenticação de dois fatores não está mais ativa para esta conta. Faça login novamente.']);
        exit;
    }

    $valido = false;

    if (preg_match('/^\d{6}$/', $codeInput)) {
        $step = TwoFactorAuth::verifyCode($twofa['secret'], $codeInput, $twofa['last_used_step']);
        if ($step !== false) {
            $upd = $conn->prepare("UPDATE user_2fa SET last_used_step = ? WHERE user_id = ?");
            $upd->execute([$step, $pending_user_id]);
            $valido = true;
        }
    } else {
        // Tenta como código de backup (formato XXXX-XXXX)
        $stmtBackup = $conn->prepare("SELECT id, code_hash FROM user_2fa_backup_codes WHERE user_id = ? AND used_at IS NULL");
        $stmtBackup->execute([$pending_user_id]);
        foreach ($stmtBackup->fetchAll(PDO::FETCH_ASSOC) as $backup) {
            if (password_verify($codeInput, $backup['code_hash'])) {
                $updBackup = $conn->prepare("UPDATE user_2fa_backup_codes SET used_at = NOW() WHERE id = ?");
                $updBackup->execute([$backup['id']]);
                $valido = true;
                break;
            }
        }
    }

    if (!$valido) {
        $rateLimiter->recordLoginAttempt('2fa:' . $pending_user_id, $client_ip, false);
        http_response_code(401);
        echo json_encode(['error' => 'Código inválido.']);
        exit;
    }

    $rateLimiter->recordLoginAttempt('2fa:' . $pending_user_id, $client_ip, true);

    // Conclui o login
    $stmtUser = $conn->prepare("SELECT id, username, email FROM users WHERE id = ? AND deletedAt IS NULL");
    $stmtUser->execute([$pending_user_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não encontrado.']);
        exit;
    }

    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_remember']);

    session_regenerate_id(true);
    Csrf::regenerateToken();

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['logged_in'] = true;

    if ($remember) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), time() + $session_lifetime, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }

    http_response_code(200);
    echo json_encode([
        'message' => 'Login bem-sucedido!',
        'username' => $user['username']
    ]);

} catch (PDOException $e) {
    api_error_response('Erro no servidor', $e, 500);
}
?>

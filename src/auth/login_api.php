<?php
// login_api.php

require_once '../config/config.php';
require_once '../core/ApiError.php';
require_once '../core/RateLimiter.php';
require_once '../core/Csrf.php';

use App\Core\RateLimiter;
use App\Core\Csrf;

// Configura CORS seguro
setupSecureCORS();

// Recebe o body primeiro (precisamos do campo remember antes de iniciar a sessão)
$raw = file_get_contents("php://input");
$data = json_decode($raw);

// Determine desired session lifetime (30 dias quando lembrar, senão 0)
$remember = false;
if (isset($data->remember) && $data->remember) {
    $remember = true;
}
$session_lifetime = $remember ? 60 * 60 * 24 * 30 : 0;
// Configura e inicia a sessão com o lifetime solicitado
setupSecureSession($session_lifetime);

header("Content-Type: application/json; charset=UTF-8");

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

require '../config/db.php'; // Sua conexão com o banco

// 2. Validação básica
if (!isset($data->email) || !isset($data->password)) {
    http_response_code(400);
    echo json_encode(['error' => 'E-mail e senha são obrigatórios.']);
    exit;
}

// 3. Verifica se e-mail/IP excederam tentativas de login mal-sucedidas
$client_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$rateLimiter = new RateLimiter($conn);
$loginCheck = $rateLimiter->checkLoginAttempts($data->email, $client_ip);

if (!$loginCheck['allowed']) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Muitas tentativas de login. Tente novamente mais tarde.',
        'retry_info' => "Limite de {$loginCheck['max_attempts']} tentativas excedido."
    ]);
    exit;
}

// 4. Busca o usuário pelo e-mail
try {
    $sql = "SELECT id, username, email, password_hash
            FROM users
            WHERE email = ? AND deletedAt IS NULL";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$data->email]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 5. Verifica se o usuário existe E se a senha está correta
    if ($user && password_verify($data->password, $user['password_hash'])) {

        $rateLimiter->recordLoginAttempt($data->email, $client_ip, true);

        // 6. Se o usuário tiver 2FA ativado, a senha correta só confirma o
        // primeiro fator — o login só se completa em verificar_2fa_api.php.
        $stmt2fa = $conn->prepare("SELECT enabled FROM user_2fa WHERE user_id = ?");
        $stmt2fa->execute([$user['id']]);
        $twofa = $stmt2fa->fetch(PDO::FETCH_ASSOC);

        if ($twofa && $twofa['enabled']) {
            // O usuário já provou conhecer a senha, então regeneramos a
            // sessão aqui também (login ainda não está completo).
            session_regenerate_id(true);
            $_SESSION['2fa_pending_user_id'] = $user['id'];
            $_SESSION['2fa_pending_remember'] = $remember;

            http_response_code(200);
            echo json_encode([
                'twofa_required' => true,
                'message' => 'Informe o código de autenticação de dois fatores.'
            ]);
            exit;
        }

        // 7. SUCESSO! Regenera o ID de sessão e o token CSRF para prevenir
        // session fixation (descarta qualquer ID/token pré-autenticação)
        session_regenerate_id(true);
        Csrf::regenerateToken();

        // Armazena os dados na sessão. Não armazene a senha ou o hash!
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['logged_in'] = true;

        // Se o cliente pediu 'remember', reforça o cookie de sessão com expiry
        if ($remember) {
            // Re-emit cookie com mesmo session id e novo expiry
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), time() + $session_lifetime, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
        }

        http_response_code(200);
        echo json_encode([
            'message' => 'Login bem-sucedido!',
            'username' => $user['username']
        ]);

    } else {
        // Falha no login (usuário não encontrado ou senha errada)
        $rateLimiter->recordLoginAttempt($data->email, $client_ip, false);
        http_response_code(401); // Unauthorized
        echo json_encode(['error' => 'E-mail ou senha inválidos.']);
    }

} catch (PDOException $e) {
    api_error_response('Erro no servidor', $e, 500);
}
?>
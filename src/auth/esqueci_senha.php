<?php
// esqueci_senha.php - Solicita a redefinição de senha por e-mail

require_once '../config/config.php';
require_once '../core/RateLimiter.php';

use App\Core\RateLimiter;

setupSecureCORS();
require_once '../core/ApiError.php';

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

if (!isset($data->email) || !filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe um e-mail válido.']);
    exit;
}

$email = trim($data->email);
$client_ip = $_SERVER['REMOTE_ADDR'] ?? null;

// Reaproveita o mecanismo de rate limiting do login, namespaced para não
// misturar contagem de tentativas de login com pedidos de redefinição de
// senha. Evita spam de e-mails (e-mail bombing) contra uma vítima.
$rateLimiter = new RateLimiter($conn);
$rateCheck = $rateLimiter->checkLoginAttempts('reset:' . $email, $client_ip);

if (!$rateCheck['allowed']) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitas solicitações. Tente novamente mais tarde.']);
    exit;
}

$rateLimiter->recordLoginAttempt('reset:' . $email, $client_ip, false);

// Mensagem de resposta é SEMPRE a mesma, exista ou não o e-mail no sistema —
// evita que o endpoint sirva para descobrir quais e-mails estão cadastrados.
$respostaGenerica = ['message' => 'Se este e-mail estiver cadastrado, enviaremos um link de redefinição de senha em instantes.'];

try {
    $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE email = ? AND deletedAt IS NULL");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $tokenPuro = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenPuro);
        $expiracao = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $sqlToken = "INSERT INTO email_verifications (user_id, email_to_verify, token_hash, type, expires_at) VALUES (?, ?, ?, 'PASSWORD_RESET', ?)";
        $stmtToken = $conn->prepare($sqlToken);
        $stmtToken->execute([$user['id'], $user['email'], $tokenHash, $expiracao]);

        require_once '../core/ServicoEmail.php';
        $servicoEmail = new ServicoEmail();
        $servicoEmail->enviarEmailRedefinicaoSenha($user['email'], $user['name'], $tokenPuro);
    }

    http_response_code(200);
    echo json_encode($respostaGenerica);

} catch (PDOException $e) {
    api_error_response('Erro ao processar solicitação', $e, 500);
}
?>

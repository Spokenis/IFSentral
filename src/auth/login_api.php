<?php
// login_api.php

require_once '../config/config.php';

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
    echo json_encode(['error' => 'Email e senha são obrigatórios.']);
    exit;
}

// 3. Busca o usuário pelo email
try {
    $sql = "SELECT id, username, email, password_hash 
            FROM users 
            WHERE email = ? AND deletedAt IS NULL";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$data->email]);
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Verifica se o usuário existe E se a senha está correta
    if ($user && password_verify($data->password, $user['password_hash'])) {
        
        // 5. SUCESSO! Armazena os dados na sessão
        // Não armazene a senha ou o hash!
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
        http_response_code(401); // Unauthorized
        echo json_encode(['error' => 'Email ou senha inválidos.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no servidor: ' . $e->getMessage()]);
}
?>
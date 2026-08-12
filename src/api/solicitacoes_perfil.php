<?php
// api/solicitacoes_perfil.php
require_once '../config/config.php';
setupSecureCORS();
header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';
require '../auth/auth_check.php';

$identifier = $_SESSION['user_id'] ?? $_SESSION['email'];
$column = isset($_SESSION['user_id']) ? 'id' : 'email';

$stmt = $conn->prepare("SELECT id, profile FROM users WHERE $column = ? AND deletedAt IS NULL");
$stmt->execute([$identifier]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não encontrado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];


// USUÁRIO COMUM: ENVIAR PEDIDO
if ($method === 'POST') {
    if ($user['profile'] !== 'User') {
        http_response_code(400);
        echo json_encode(['error' => 'Você já possui privilégios avançados.']);
        exit;
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $message = trim((string)($data['message'] ?? ''));

    if (mb_strlen($message) < 5) {
        http_response_code(400);
        echo json_encode(['error' => 'Mensagem deve ter pelo menos 5 caracteres.']);
        exit;
    }

    if (mb_strlen($message) > 2000) {
        http_response_code(400);
        echo json_encode(['error' => 'Mensagem excede o limite de 2000 caracteres.']);
        exit;
    }


    $stmt = $conn->prepare("SELECT id FROM profile_requests WHERE user_id = ? AND status = 'pendente'");
    $stmt->execute([$user['id']]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Você já possui uma solicitação em análise.']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO profile_requests (user_id, message) VALUES (?, ?)");
    $stmt->execute([$user['id'], $message]);
    echo json_encode(['success' => true, 'message' => 'Solicitação enviada com sucesso. Aguarde aprovação.']);
    exit;
}

// VALIDAÇÃO: DAQUI PARA BAIXO, APENAS ADMIN
if ($user['profile'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

// ADMIN: LISTAR PEDIDOS PENDENTES
if ($method === 'GET') {
    $stmt = $conn->query("
        SELECT pr.id, pr.message, pr.createdAt, u.name, u.email, u.id as user_id 
        FROM profile_requests pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.status = 'pendente'
        ORDER BY pr.createdAt ASC
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ADMIN: APROVAR OU REJEITAR
if ($method === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);
    $request_id = $data['request_id'] ?? null;
    $status = $data['status'] ?? null;

    if (!$request_id || !in_array($status, ['aprovado', 'rejeitado'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetros inválidos.']);
        exit;
    }

    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("UPDATE profile_requests SET status = ? WHERE id = ?");
        $stmt->execute([$status, $request_id]);

        if ($status === 'aprovado') {
            $stmt = $conn->prepare("SELECT user_id FROM profile_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            // Promove o usuário para Moderator
            $stmt = $conn->prepare("UPDATE users SET profile = 'Moderator' WHERE id = ?");
            $stmt->execute([$req['user_id']]);
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => "Solicitação marcada como $status."]);
    } catch (PDOException $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno ao processar a solicitação.']);
    }
    exit;
}
<?php
require '../auth/auth_check.php';
require '../config/db.php';

// Descobre o que temos na sessão (ID ou Email)
$identifier = $_SESSION['user_id'] ?? $_SESSION['email'] ?? null;
$column = isset($_SESSION['user_id']) ? 'id' : 'email';

// Bloqueia o acesso caso não seja Admin
$stmt = $conn->prepare("SELECT profile FROM users WHERE $column = ? AND deletedAt IS NULL");
$stmt->execute([$identifier]);
$profile_logado = $stmt->fetchColumn();

if ($profile_logado !== 'Admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Acesso negado. Apenas administradores podem ver os logs.']);
    exit;
}

// Caminho para o log escrito pelo subscriber
$log_file = __DIR__ . '/../../logs/mqtt_subscriber.log';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if ($limit <= 0) {
    $limit = 100;
}

$response = [
    'success' => true,
    'total_lines' => 0,
    'logs' => []
];

if (file_exists($log_file)) {
    // Lê o arquivo inteiro em um array ignorando quebras vazias
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines !== false) {
        $response['total_lines'] = count($lines);
        // Pega apenas as últimas N linhas
        $response['logs'] = array_slice($lines, -$limit);
    } else {
        $response['success'] = false;
        $response['error'] = 'Falha ao ler arquivo de log.';
    }
} else {
    $response['success'] = false;
    $response['error'] = 'Arquivo de log não encontrado em ' . $log_file;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($response);
?>


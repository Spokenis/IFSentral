<?php
// buscar_payloads.php (Corrigido com PDO::PARAM_INT)

require_once '../config/config.php';
require_once '../core/AuthMiddleware.php';
setupSecureCORS();

use App\Core\AuthMiddleware;

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';

$deviceAuth = AuthMiddleware::validateApiKey($conn);
if (!$deviceAuth) {
    http_response_code(401);
    echo json_encode(['error' => 'Chave de API inválida ou não fornecida.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}
if (!isset($_GET['device_id']) || !is_numeric($_GET['device_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'device_id é obrigatório e deve ser numérico.']);
    exit;
}
$device_id = intval($_GET['device_id']);

if (intval($deviceAuth['id']) !== $device_id) {
    http_response_code(403);
    echo json_encode(['error' => 'A Chave de API não pertence a este dispositivo.']);
    exit;
}

try {
    // --- CONSTRUÇÃO DINÂMICA DA QUERY ---
    $params = [$device_id];
    $sql = "
        SELECT payload, created_at 
        FROM device_payloads 
        WHERE device_id = ? 
    ";

    if (isset($_GET['startDate']) && !empty($_GET['startDate'])) {
        $sql .= " AND created_at >= ? ";
        $params[] = $_GET['startDate'];
    }
    
    if (isset($_GET['endDate']) && !empty($_GET['endDate'])) {
        $sql .= " AND created_at <= ? ";
        $params[] = $_GET['endDate'] . ' 23:59:59';
    }

    $sql .= " ORDER BY created_at DESC ";

    // *** INÍCIO DA CORREÇÃO ***
    
    // 1. Define o limite (padrão 10)
    $limit = 10;
    if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
        $limit = intval($_GET['limit']);
    }
    
    // 2. Adiciona o placeholder para o LIMIT
    $sql .= " LIMIT ? ";

    // 3. Prepara a query
    $stmt = $conn->prepare($sql);
    
    // 4. Amarra (bind) os parâmetros de data/id
    $param_index = 1;
    foreach ($params as $value) {
        $stmt->bindValue($param_index, $value);
        $param_index++;
    }
    
    // 5. Amarra (bind) o LIMIT como um INTEIRO (PDO::PARAM_INT)
    $stmt->bindValue($param_index, $limit, PDO::PARAM_INT);
    
    // 6. Executa
    $stmt->execute();
    
    // *** FIM DA CORREÇÃO ***

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $payloads_formatados = [];
    foreach ($results as $row) {
        $payloads_formatados[] = [
            'payload' => json_decode($row['payload']),
            'created_at' => $row['created_at']
        ];
    }
    
    echo json_encode($payloads_formatados);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar payloads: ' . $e->getMessage()]);
}
?>
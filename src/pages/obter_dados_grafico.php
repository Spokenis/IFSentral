<?php
// obter_dados_grafico.php (Corrigido com PDO::PARAM_INT)

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ... (Validação de parâmetros - sem alterações) ...
if (!isset($_GET['device_id']) || !is_numeric($_GET['device_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'device_id é obrigatório.']);
    exit;
}
if (!isset($_GET['json_key']) || empty($_GET['json_key'])) {
    http_response_code(400);
    echo json_encode(['error' => 'json_key é obrigatório.']);
    exit;
}

$device_id = intval($_GET['device_id']);
$json_key = $_GET['json_key'];
$json_path = '$.' . $json_key; 

try {
    // Validação de Segurança
    $authSql = "SELECT 1 FROM devices d JOIN users_projects up ON d.project_id = up.project_id WHERE d.id = ? AND up.user_id = ?";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$device_id, $_SESSION['user_id']]);
    if ($authStmt->rowCount() == 0) {
        http_response_code(403); 
        echo json_encode(['error' => 'Permissão negada.']);
        exit;
    }

    // --- CONSTRUÇÃO DINÂMICA DA QUERY ---
    $params = [$json_path, $device_id, $json_path];
    $sql = "
        SELECT 
            p.created_at AS time,
            JSON_UNQUOTE(JSON_EXTRACT(p.payload, ?)) AS value
        FROM 
            device_payloads p
        WHERE 
            p.device_id = ?
            AND JSON_EXTRACT(p.payload, ?) IS NOT NULL
    ";
    
    if (isset($_GET['startDate']) && !empty($_GET['startDate'])) {
        $sql .= " AND p.created_at >= ? ";
        $params[] = $_GET['startDate'];
    }
    
    if (isset($_GET['endDate']) && !empty($_GET['endDate'])) {
        $sql .= " AND p.created_at <= ? ";
        $params[] = $_GET['endDate'] . ' 23:59:59';
    }

    $sql .= " ORDER BY p.created_at ASC ";

    // *** INÍCIO DA CORREÇÃO ***

    // 1. Define o limite (padrão 500)
    $limit = 500;
    if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
        $limit = intval($_GET['limit']);
    }
    
    // 2. Adiciona o placeholder para o LIMIT
    $sql .= " LIMIT ? ";

    // 3. Prepara a query
    $stmt = $conn->prepare($sql);
    
    // 4. Amarra (bind) os parâmetros (json_path, device_id, json_path, datas)
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
    
    echo json_encode($results);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco: ' . $e->getMessage()]);
}
?>
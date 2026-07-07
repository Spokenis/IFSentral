<?php
// src/api/obter_dados_grafico_avancado.php
// Busca dados de múltiplos dispositivos/variáveis com filtro de data

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

if (!isset($_GET['chart_id']) || !is_numeric($_GET['chart_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'chart_id é obrigatório.']);
    exit;
}

$chart_id = intval($_GET['chart_id']);
$date_start = $_GET['date_start'] ?? null;
$date_end = $_GET['date_end'] ?? null;

// Obter user_id da sessão ou do banco de dados basado no email
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id && isset($_SESSION['email'])) {
    try {
        $sql_user = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->execute([$_SESSION['email']]);
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $user_id = $user_data['id'];
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Erro ao validar usuário']);
        exit;
    }
}

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

try {
    // Validar acesso ao gráfico
    $authSql = "
        SELECT c.project_id FROM charts c
        JOIN users_projects up ON c.project_id = up.project_id
        WHERE c.id = ? AND up.user_id = ?
    ";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$chart_id, $user_id]);
    
    if ($authStmt->rowCount() == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada.']);
        exit;
    }
    
    // Buscar configuração do gráfico
    $chart_sql = "SELECT * FROM charts WHERE id = ?";
    $chart_stmt = $conn->prepare($chart_sql);
    $chart_stmt->execute([$chart_id]);
    $chart = $chart_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$chart) {
        http_response_code(404);
        echo json_encode(['error' => 'Gráfico não encontrado.']);
        exit;
    }
    
    // Buscar datasets do gráfico
    $datasets_sql = "SELECT * FROM chart_datasets WHERE chart_id = ? ORDER BY sort_order ASC";
    $datasets_stmt = $conn->prepare($datasets_sql);
    $datasets_stmt->execute([$chart_id]);
    $datasets = $datasets_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar dados para cada dataset
    $result = [
        'chart' => [
            'id' => $chart['id'],
            'name' => $chart['name'],
            'chart_type' => $chart['chart_type'],
            'time_range' => $chart['time_range']
        ],
        'datasets' => []
    ];
    
    foreach ($datasets as $dataset) {
        // Construir query com filtro de data
        $data_sql = "
            SELECT 
                dp.created_at,
                dp.payload
            FROM 
                device_payloads dp
            WHERE 
                dp.device_id = ?
        ";
        
        $conditions = [];
        $params = [$dataset['device_id']];
        
        // Filtro de data
        if ($date_start) {
            $data_sql .= " AND dp.created_at >= ?";
            $params[] = $date_start;
        }
        if ($date_end) {
            $data_sql .= " AND dp.created_at <= ?";
            $params[] = $date_end;
        }
        
        $data_sql .= " ORDER BY dp.created_at ASC LIMIT 1000";
        
        $data_stmt = $conn->prepare($data_sql);
        $data_stmt->execute($params);
        $payloads = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Processar dados para extrair variável específica
        $data_points = [];
        foreach ($payloads as $payload) {
            $payload_obj = json_decode($payload['payload'], true);
            
            if (isset($payload_obj[$dataset['variable_name']])) {
                $data_points[] = [
                    'x' => $payload['created_at'],
                    'y' => $payload_obj[$dataset['variable_name']]
                ];
            }
        }
        
        $result['datasets'][] = [
            'id' => $dataset['id'],
            'device_id' => $dataset['device_id'],
            'variable_name' => $dataset['variable_name'],
            'alias' => $dataset['alias'] ?? $dataset['variable_name'],
            'color' => $dataset['color'],
            'line_style' => $dataset['line_style'],
            'axis' => $dataset['axis'],
            'data' => $data_points,
            'data_count' => count($data_points)
        ];
    }
    
    echo json_encode($result);

} catch (PDOException $e) {
    http_response_code(500);
    if (APP_ENV === 'production') {
        echo json_encode(['error' => 'Erro ao buscar dados.']);
    } else {
        echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
    }
}
?>

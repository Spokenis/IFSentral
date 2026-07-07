<?php
/**
 * API: Obter Dados do Gráfico com Payload
 * Retorna os dados reais para renderizar um gráfico
 */

require_once __DIR__ . '/../config/config.php';
setupSecureCORS();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    if (!isset($_GET['chart_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'chart_id é obrigatório']);
        exit;
    }

    $chart_id = intval($_GET['chart_id']);
    $user_id = $_SESSION['user_id'] ?? null;

    // Buscar informações do gráfico
    $chartSql = "SELECT c.*, p.id as project_id, p.public as project_public
                 FROM charts c 
                 JOIN projects p ON c.project_id = p.id 
                 WHERE c.id = ?";
    
    $chartStmt = $conn->prepare($chartSql);
    $chartStmt->execute([$chart_id]);
    $chart = $chartStmt->fetch(PDO::FETCH_ASSOC);

    if (!$chart) {
        http_response_code(404);
        echo json_encode(['error' => 'Gráfico não encontrado']);
        exit;
    }

    // Validar permissão
    // Se o gráfico não é público E o projeto não é público, verificar permissão do usuário
    if (!$chart['is_public'] && !$chart['project_public'] && $user_id) {
        // Se não é público, verificar se o usuário é participante do projeto
        $authSql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ?";
        $authStmt = $conn->prepare($authSql);
        $authStmt->execute([$chart['project_id'], $user_id]);
        
        if (!$authStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada']);
            exit;
        }
    }

    // Buscar dados dos payloads para o gráfico
    $payloadSql = "
        SELECT d.id as device_id, d.name as device_name, dp.*, dp.created_at as timestamp
        FROM device_payloads dp
        JOIN devices d ON dp.device_id = d.id
        WHERE d.project_id = ? 
        ORDER BY dp.created_at DESC
        LIMIT 1000
    ";
    
    $payloadStmt = $conn->prepare($payloadSql);
    $payloadStmt->execute([$chart['project_id']]);
    $payloads = $payloadStmt->fetchAll(PDO::FETCH_ASSOC);

    // Se é gráfico avançado, buscar datasets
    $datasets_info = [];
    if ($chart['is_multi_device']) {
        $datasetsSql = "
            SELECT cd.*, d.name as device_name
            FROM chart_datasets cd
            LEFT JOIN devices d ON cd.device_id = d.id
            WHERE cd.chart_id = ?
            ORDER BY cd.sort_order
        ";
        
        $datasetsStmt = $conn->prepare($datasetsSql);
        $datasetsStmt->execute([$chart_id]);
        $datasets_info = $datasetsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'chart' => $chart,
        'payloads' => $payloads,
        'datasets' => $datasets_info
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao obter gráfico: ' . $e->getMessage()]);
}

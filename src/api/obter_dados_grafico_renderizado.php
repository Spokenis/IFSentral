<?php
/**
 * API: Obter Dados do Gráfico com Payload
 * Retorna os dados reais para renderizar um gráfico (Otimizado via Banco de Dados com Bucketing Dinâmico)
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

    // --- PROCESSAMENTO E EXTRAÇÃO DIRETA VIA BANCO DE DADOS ---

    // 1. Unificar a descoberta de datasets (Multi-dispositivo ou Simples/Legado)
    $datasets = [];
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
        $datasets = $datasetsStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fallback e retrocompatibilidade para gráficos simples que usam colunas da tabela principal
        $chartConfig = json_decode($chart['config'] ?? '{}', true);
        $datasets[] = [
            'id' => null,
            'device_id' => $chart['device_id'],
            'device_name' => null,
            'variable_name' => $chart['json_key'],
            'alias' => $chart['json_key'],
            'color' => $chartConfig['theme_color'] ?? '#3182ce',
            'line_style' => 'solid',
            'axis' => 'y'
        ];
    }

    // 2. Mapeamento, Bucketing e extração dos pontos das séries
    $rendered_datasets = [];
    foreach ($datasets as $dataset) {
        $var_name = $dataset['variable_name'];
        
        // Proteção contra caminhos maliciosos ou injeção de caracteres no path do JSON
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $var_name)) {
            continue;
        }

        // Determinar o intervalo do bucket (em segundos) com base no período selecionado
        $bucket_seconds = 60; // Padrão: 1 minuto
        
        if (!empty($chart['date_start']) && !empty($chart['date_end'])) {
            $start = strtotime($chart['date_start']);
            $end = strtotime($chart['date_end']);
            $diff_hours = ($end - $start) / 3600;

            if ($diff_hours > 720) {       // Mais de 30 dias -> buckets de 1 dia
                $bucket_seconds = 86400;
            } elseif ($diff_hours > 168) { // Mais de 7 dias -> buckets de 2 horas
                $bucket_seconds = 7200;
            } elseif ($diff_hours > 24) {  // Mais de 1 dia -> buckets de 30 minutos
                $bucket_seconds = 1800;
            } elseif ($diff_hours > 6) {   // Mais de 6 horas -> buckets de 5 minutos
                $bucket_seconds = 300;
            }
        }

        // Query com agrupamento temporal e agregação matemática (AVG)
        $data_sql = "
            SELECT 
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(dp.created_at) / ?) * ?) AS x,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(dp.payload, CONCAT('$.', ?))) AS DECIMAL(10,4))) AS y
            FROM 
                device_payloads dp
            WHERE 
                dp.device_id = ?
                AND JSON_EXTRACT(dp.payload, CONCAT('$.', ?)) IS NOT NULL
        ";
        
        $params = [
            $bucket_seconds,
            $bucket_seconds,
            $var_name,
            $dataset['device_id'],
            $var_name
        ];
        
        // Filtros temporais baseados no gráfico
        if (!empty($chart['date_start'])) {
            $data_sql .= " AND dp.created_at >= ?";
            $params[] = $chart['date_start'];
        }
        if (!empty($chart['date_end'])) {
            $data_sql .= " AND dp.created_at <= ?";
            $params[] = $chart['date_end'];
        }
        
        // Query com agrupamento temporal, mas retornando a HORA EXATA da leitura
        $data_sql = "
            SELECT 
                MIN(dp.created_at) AS x,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(dp.payload, CONCAT('$.', ?))) AS DECIMAL(10,4))) AS y
            FROM 
                device_payloads dp
            WHERE 
                dp.device_id = ?
                AND JSON_EXTRACT(dp.payload, CONCAT('$.', ?)) IS NOT NULL
        ";
        
        $params = [
            $var_name,
            $dataset['device_id'],
            $var_name
        ];
        
        // Filtros temporais baseados no gráfico
        if (!empty($chart['date_start'])) {
            $data_sql .= " AND dp.created_at >= ?";
            $params[] = $chart['date_start'];
        }
        if (!empty($chart['date_end'])) {
            $data_sql .= " AND dp.created_at <= ?";
            $params[] = $chart['date_end'];
        }
        
        // Agrupa pelos blocos de tempo calculados
        $data_sql .= " 
            GROUP BY FLOOR(UNIX_TIMESTAMP(dp.created_at) / ?)
            ORDER BY x ASC 
            LIMIT 2000
        ";
        $params[] = $bucket_seconds;
        
        $data_stmt = $conn->prepare($data_sql);
        $data_stmt->execute($params);
        $data_points = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Garante a tipagem correta do valor numérico (útil após o AVG retornar string no PDO em alguns drivers)
        foreach ($data_points as &$point) {
            $point['y'] = $point['y'] !== null ? (float)$point['y'] : null;
        }
        unset($point);

        $rendered_datasets[] = [
            'id' => $dataset['id'],
            'device_id' => $dataset['device_id'],
            'device_name' => $dataset['device_name'] ?? null,
            'variable_name' => $var_name,
            'alias' => $dataset['alias'] ?? $var_name,
            'color' => $dataset['color'],
            'line_style' => $dataset['line_style'] ?? 'solid',
            'axis' => $dataset['axis'] ?? 'y',
            'data' => $data_points,
            'data_count' => count($data_points)
        ];
    }

    // 3. Resposta padronizada com o contrato unificado
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'chart' => $chart,
        'datasets' => $rendered_datasets
    ]);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao obter gráfico: ' . $e->getMessage()]);
}
?>
<?php
// listar_graficos.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';
require '../auth/auth_check.php'; // Protegido

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id é obrigatório.']);
    exit;
}
$project_id = intval($_GET['project_id']);

try {
    // Verificar se o projeto é público ou se o usuário é participante
    $checkSql = "SELECT `public` FROM projects WHERE id = ? AND deletedAt IS NULL";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$project_id]);
    $projectData = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$projectData) {
        http_response_code(404);
        echo json_encode(['error' => 'Projeto não encontrado']);
        exit;
    }
    
    // Se o projeto não for público, validar se o usuário é participante
    if (!$projectData['public']) {
        $authSql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ?";
        $authStmt = $conn->prepare($authSql);
        $authStmt->execute([$project_id, $_SESSION['user_id']]);
        if ($authStmt->rowCount() == 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada para ver gráficos deste projeto.']);
            exit;
        }
    }
    
    // Buscar todos os gráficos do projeto (incluindo novos com múltiplos datasets)
    $sql = "
        SELECT 
            c.id, 
            c.name, 
            c.chart_type, 
            c.json_key,
            c.time_range,
            c.date_start,
            c.date_end,
            c.is_multi_device,
            c.is_public,
            d.name AS device_name, 
            d.id AS device_id,
            COUNT(cd.id) as dataset_count
        FROM charts c
        LEFT JOIN devices d ON c.device_id = d.id
        LEFT JOIN chart_datasets cd ON c.id = cd.chart_id
        WHERE c.project_id = ?
        GROUP BY c.id
        ORDER BY c.createdAt DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para gráficos com múltiplos datasets, incluir informações dos datasets
    $enrichedResults = [];
    foreach ($results as $chart) {
        $enriched = $chart;
        
        // Mapear is_public para visibility
        $enriched['visibility'] = $chart['is_public'] == 1 ? 'Publico' : 'Privado';
        
        // Se é um gráfico com múltiplos datasets, buscar os detalhes
        if ($chart['is_multi_device'] || $chart['dataset_count'] > 1) {
            $datasetSql = "
                SELECT 
                    cd.id,
                    cd.device_id,
                    cd.variable_name,
                    cd.alias,
                    cd.color,
                    cd.line_style,
                    cd.axis,
                    d.name as device_name
                FROM chart_datasets cd
                LEFT JOIN devices d ON cd.device_id = d.id
                WHERE cd.chart_id = ?
                ORDER BY cd.sort_order
            ";
            
            $datasetStmt = $conn->prepare($datasetSql);
            $datasetStmt->execute([$chart['id']]);
            $datasets = $datasetStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $enriched['datasets'] = $datasets;
            $enriched['is_advanced'] = true;
        } else {
            $enriched['datasets'] = [];
            $enriched['is_advanced'] = false;
        }
        
        $enrichedResults[] = $enriched;
    }
    
    echo json_encode($enrichedResults);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar gráficos: ' . $e->getMessage()]);
}
?>
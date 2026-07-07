<?php
/**
 * API: Exportar Dados do Projeto
 * Retorna todos os payloads do projeto formatados
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/AuthMiddleware.php';
setupSecureCORS();

use App\Core\AuthMiddleware;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    if (!isset($_GET['project_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id é obrigatório']);
        exit;
    }

    $project_id = intval($_GET['project_id']);
    $user_id = AuthMiddleware::requireAuth();

    // Validar se o usuário tem acesso ao projeto
    if (!AuthMiddleware::hasProjectAccess($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada']);
        exit;
    }

    // Buscar informações do projeto
    $projectSql = "SELECT name, createdAt FROM projects WHERE id = ?";
    $projectStmt = $conn->prepare($projectSql);
    $projectStmt->execute([$project_id]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

    // Buscar todos os dispositivos do projeto
    $devicesSql = "SELECT id, name FROM devices WHERE project_id = ? ORDER BY name";
    $devicesStmt = $conn->prepare($devicesSql);
    $devicesStmt->execute([$project_id]);
    $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Buscar todos os payloads
    $payloadsSql = "
        SELECT 
            dp.id,
            dp.device_id,
            d.name as device_name,
            dp.payload,
            dp.created_at
        FROM device_payloads dp
        JOIN devices d ON dp.device_id = d.id
        WHERE d.project_id = ?
        ORDER BY dp.created_at DESC
        LIMIT 10000
    ";
    
    $payloadsStmt = $conn->prepare($payloadsSql);
    $payloadsStmt->execute([$project_id]);
    $payloads = $payloadsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Processar payloads para formato estruturado
    $processedData = [];
    foreach ($payloads as $payload) {
        try {
            $payloadData = json_decode($payload['payload'], true);
            
            $row = [
                'id' => $payload['id'],
                'device_id' => $payload['device_id'],
                'device_name' => $payload['device_name'],
                'timestamp' => $payload['created_at']
            ];
            
            // Adicionar campos do JSON do payload
            if (is_array($payloadData)) {
                foreach ($payloadData as $key => $value) {
                    $row[$key] = $value;
                }
            }
            
            $processedData[] = $row;
        } catch (Exception $e) {
            // Ignorar payloads inválidos
            continue;
        }
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'project' => $project,
        'devices' => $devices,
        'data' => $processedData,
        'total_records' => count($processedData)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao exportar dados: ' . $e->getMessage()]);
}
?>

<?php
/**
 * API: Listar Todos os Dispositivos do Usuário
 * Retorna todos os dispositivos de todos os projetos onde o usuário participa
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
setupSecureCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    // Obter ID do usuário da sessão
    $user_id = $_SESSION['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não autenticado']);
        exit;
    }
    
    // Buscar todos os dispositivos dos projetos onde o usuário participa
    $sql = "SELECT 
                d.id,
                d.name,
                d.description,
                d.api_key,
                d.createdAt,
                d.updatedAt,
                p.id as project_id,
                p.name as project_name,
                p.description as project_description,
                r.name as role_name,
                r.id as role_id
            FROM devices d
            INNER JOIN projects p ON d.project_id = p.id
            INNER JOIN users_projects up ON p.id = up.project_id
            INNER JOIN roles r ON up.role_id = r.id
            WHERE up.user_id = ? 
            AND d.deletedAt IS NULL 
            AND p.deletedAt IS NULL
            ORDER BY p.name ASC, d.name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar dispositivos por projeto
    $devicesByProject = [];
    $totalDevices = 0;
    
    foreach ($devices as $device) {
        $projectId = $device['project_id'];
        
        if (!isset($devicesByProject[$projectId])) {
            $devicesByProject[$projectId] = [
                'project_id' => $projectId,
                'project_name' => $device['project_name'],
                'project_description' => $device['project_description'],
                'role_name' => $device['role_name'],
                'role_id' => $device['role_id'],
                'devices' => []
            ];
        }
        
        $devicesByProject[$projectId]['devices'][] = [
            'id' => $device['id'],
            'name' => $device['name'],
            'description' => $device['description'],
            'api_key' => $device['api_key'],
            'createdAt' => $device['createdAt'],
            'updatedAt' => $device['updatedAt']
        ];
        
        $totalDevices++;
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'total_devices' => $totalDevices,
        'total_projects' => count($devicesByProject),
        'data' => array_values($devicesByProject)
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao listar dispositivos: ' . $e->getMessage()]);
}

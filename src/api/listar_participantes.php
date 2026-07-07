<?php
// listar_participantes.php

require_once '../config/config.php';
require_once '../core/AuthMiddleware.php';
setupSecureCORS();

use App\Core\AuthMiddleware;

header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id é obrigatório.']);
    exit;
}
$project_id = intval($_GET['project_id']);
$user_id = AuthMiddleware::requireAuth();

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
        if (!AuthMiddleware::hasProjectAccess($conn, $user_id, $project_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada para ver participantes deste projeto.']);
            exit;
        }
    }
    
    // Busca na tabela 'users_projects' e junta com 'users' e 'roles'
    $sql = "
        SELECT 
            u.id AS user_id,
            u.name AS user_name,
            u.username AS user_username,
            u.profile_picture,
            r.name AS role_name
        FROM 
            users_projects up
        JOIN 
            users u ON up.user_id = u.id
        JOIN 
            roles r ON up.role_id = r.id
        WHERE 
            up.project_id = ?
        ORDER BY 
            r.id ASC, u.name ASC -- Ordena por Gerente (ID 1) primeiro, depois por nome
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar participantes: ' . $e->getMessage()]);
}
?>
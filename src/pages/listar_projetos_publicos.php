<?php
// listar_projetos_publicos.php (Versão Pública)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$logged_user_id = intval($_SESSION['user_id'] ?? 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
// NÃO PRECISA DE CREDENCIAIS, POIS É PÚBLICA
require '../config/db.php';
// REMOVEMOS O 'auth_check.php'. ESTA API AGORA É PÚBLICA.

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

try {
    // SQL para buscar TODOS os projetos públicos com seus gerentes e contagens
    $sql = "
        SELECT 
            p.id, 
            p.name, 
            p.description,
            p.maxUsers,
            u.name AS manager_name,
            
            (SELECT COUNT(*) FROM users_projects up_total WHERE up_total.project_id = p.id) AS participant_count,
            (SELECT COUNT(*) FROM devices d WHERE d.project_id = p.id AND d.deletedAt IS NULL) AS device_count,
            (
                SELECT 1
                FROM users_projects up_me
                WHERE up_me.project_id = p.id AND up_me.user_id = ?
                LIMIT 1
            ) AS is_member,
            
            GROUP_CONCAT(t.name SEPARATOR ',') AS project_tags 
            
        FROM 
            projects p
        JOIN 
            users_projects up ON up.project_id = p.id AND up.role_id = 1
        JOIN 
            users u ON u.id = up.user_id
        LEFT JOIN project_tags pt ON p.id = pt.project_id
        LEFT JOIN tags t ON pt.tag_id = t.id
        WHERE 
            p.public = 1 AND p.deletedAt IS NULL
        GROUP BY
            p.id, p.name, p.description, p.maxUsers, u.name
        ORDER BY 
            p.name ASC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$logged_user_id]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar projetos públicos: ' . $e->getMessage()]);
}
?>
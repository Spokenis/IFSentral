<?php
// listar_devices.php (Atualizado)

require_once '../config/config.php';
require_once __DIR__ . '/../core/ApiError.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php'; // ADICIONADO: Validar autenticação

try {
    // BUG FIX: Remover api_key (nunca expor chaves privadas pela API)
    $sql = "
        SELECT 
            d.id, 
            d.name, 
            d.description, 
            d.createdAt,
            d.project_id, 
            p.name AS project_name,
            u.username AS user_username
        FROM 
            devices d
        JOIN 
            projects p ON d.project_id = p.id
        JOIN 
            users u ON d.user_id = u.id
        JOIN
            users_projects up ON up.project_id = d.project_id AND up.user_id = ?
        WHERE 
            d.deletedAt IS NULL AND p.deletedAt IS NULL" . (isset($_GET['project_id']) ? " AND d.project_id = ?" : "") . "
        ORDER BY 
            d.createdAt DESC
    ";
    // Só dispositivos dos projetos de que o usuário participa (antes vinham os
    // de todos os projetos, inclusive privados). ?project_id= filtra um projeto.
    $params = [$_SESSION['user_id']];
    if (isset($_GET['project_id'])) {
        $params[] = intval($_GET['project_id']);
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);

} catch (PDOException $e) {
    api_error_response('Erro ao buscar dispositivos', $e, 500);
}
?>
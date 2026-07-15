<?php
// listar_devices.php (Atualizado)

require_once '../config/config.php';
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
        WHERE 
            d.deletedAt IS NULL
        ORDER BY 
            d.createdAt DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar dispositivos: ' . $e->getMessage()]);
}
?>
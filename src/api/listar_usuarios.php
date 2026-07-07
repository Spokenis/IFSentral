<?php
// listar_usuarios.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php'; // BUG FIX: Requerer autenticação

try {
    // Nunca selecione a coluna password_hash
    $sql = "
        SELECT 
            id, 
            name, 
            email, 
            username,
            profile,
            createdAt
        FROM 
            users
        WHERE 
            deletedAt IS NULL
        ORDER BY 
            createdAt DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar usuários: ' . $e->getMessage()]);
}
?>
<?php
// listar_tags.php

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");
require '../config/db.php';
require '../auth/auth_check.php'; // Só usuários logados podem ver as tags

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

try {
    // Busca todas as tags existentes
    $sql = "SELECT id, name FROM tags ORDER BY name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Transforma para o formato {value: id, text: name}
    // que o TomSelect (nosso seletor) espera
    $tags_formatadas = [];
    foreach ($results as $row) {
        $tags_formatadas[] = [
            'value' => $row['id'],
            'text' => $row['name']
        ];
    }
    
    echo json_encode($tags_formatadas);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar tags: ' . $e->getMessage()]);
}
?>
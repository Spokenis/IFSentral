<?php
// listar_usuarios.php

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php'; // BUG FIX: Requerer autenticação

// Apenas Admin/Moderator podem ver a lista completa com e-mails — qualquer
// outro usuário logado recebia o e-mail de todos os usuários do sistema.
$identifier = $_SESSION['user_id'] ?? $_SESSION['email'] ?? null;
$column = isset($_SESSION['user_id']) ? 'id' : 'email';
$stmtRole = $conn->prepare("SELECT profile FROM users WHERE $column = ? AND deletedAt IS NULL");
$stmtRole->execute([$identifier]);
$requester = $stmtRole->fetch(PDO::FETCH_ASSOC);
$isPrivileged = $requester && in_array($requester['profile'], ['Admin', 'Moderator']);

try {
    // Nunca selecione a coluna password_hash. Usuários sem privilégio não
    // recebem o e-mail alheio, apenas dados já públicos dentro da plataforma.
    $emailColumn = $isPrivileged ? 'email' : 'NULL as email';
    $sql = "
        SELECT
            id,
            name,
            $emailColumn,
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
        api_error_response('Erro ao buscar usuários', $e, 500);
    }
?>
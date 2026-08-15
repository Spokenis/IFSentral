<?php
/**
 * API: Deletar Foto de Perfil
 * Remove a foto de perfil do usuário
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/ApiError.php';
require_once __DIR__ . '/../core/Csrf.php';

use App\Core\Csrf;

header('Content-Type: application/json; charset=utf-8');
setupSecureCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

Csrf::requireValidToken();

try {
    // Obter ID do usuário da sessão
    $user_id = $_SESSION['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não autenticado']);
        exit;
    }
    
    // Buscar foto atual
    $sql = "SELECT profile_picture FROM users WHERE id = ? AND deletedAt IS NULL";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $currentPhoto = $stmt->fetchColumn();
    
    if (!$currentPhoto) {
        http_response_code(404);
        echo json_encode(['error' => 'Nenhuma foto de perfil para deletar']);
        exit;
    }
    
    // Atualizar banco de dados
    $updateSql = "UPDATE users SET profile_picture = NULL WHERE id = ? AND deletedAt IS NULL";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$user_id]);
    
    // Deletar arquivo físico
    $filePath = __DIR__ . '/../../' . $currentPhoto;
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Foto de perfil removida com sucesso'
    ]);
    
} catch (PDOException $e) {
    api_error_response('Erro ao deletar foto de perfil', $e, 500);
} catch (Exception $e) {
    api_error_response('Erro inesperado ao deletar foto de perfil', $e, 500);
}

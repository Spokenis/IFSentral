<?php
/**
 * API: Upload de Foto de Perfil
 * Permite ao usuário fazer upload de sua foto de perfil
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    
    // Verificar se o arquivo foi enviado
    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE) {
        http_response_code(400);
        echo json_encode(['error' => 'Nenhuma foto foi enviada']);
        exit;
    }
    
    $file = $_FILES['profile_picture'];
    
    // Verificar erros no upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'O arquivo excede o tamanho máximo permitido',
            UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o tamanho máximo permitido',
            UPLOAD_ERR_PARTIAL => 'O arquivo foi enviado parcialmente',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever arquivo no disco',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP interrompeu o upload',
        ];
        
        http_response_code(400);
        echo json_encode(['error' => $errorMessages[$file['error']] ?? 'Erro desconhecido no upload']);
        exit;
    }
    
    // Validar tipo de arquivo (finfo + getimagesize para maior robustez)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $fileType = $finfo ? finfo_file($finfo, $file['tmp_name']) : mime_content_type($file['tmp_name']);
    if ($finfo) { finfo_close($finfo); }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedTypes[$fileType])) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de arquivo não permitido. Use JPG, PNG, GIF ou WEBP']);
        exit;
    }

    // Verifica se arquivo é realmente uma imagem
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        http_response_code(400);
        echo json_encode(['error' => 'O arquivo enviado não é uma imagem válida.']);
        exit;
    }

    // Validar tamanho do arquivo (máximo 5MB)
    $maxFileSize = 5 * 1024 * 1024; // 5MB em bytes
    if ($file['size'] > $maxFileSize) {
        http_response_code(400);
        echo json_encode(['error' => 'O arquivo é muito grande. Tamanho máximo: 5MB']);
        exit;
    }
    
    // Criar diretório de uploads se não existir
    $uploadDir = __DIR__ . '/../../uploads/profile/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Gerar nome único para o arquivo usando extensão derivada do mime type
    $extension = $allowedTypes[$fileType];
    $fileName = 'user_' . $user_id . '_' . time() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    // Buscar foto antiga para deletar
    $sqlOldPhoto = "SELECT profile_picture FROM users WHERE id = ? AND deletedAt IS NULL";
    $stmtOldPhoto = $conn->prepare($sqlOldPhoto);
    $stmtOldPhoto->execute([$user_id]);
    $oldPhoto = $stmtOldPhoto->fetchColumn();
    
    // Garante que o arquivo foi enviado via POST e move para o diretório de destino
    if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $filePath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao salvar o arquivo']);
        exit;
    }

    // Ajusta permissões para evitar execução
    @chmod($filePath, 0644);
    
    // Caminho relativo para salvar no banco
    $relativePath = 'uploads/profile/' . $fileName;
    
    // Atualizar banco de dados
    $sql = "UPDATE users 
            SET profile_picture = ? 
            WHERE id = ? AND deletedAt IS NULL";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt->execute([$relativePath, $user_id])) {
        // Se falhar, deletar arquivo
        unlink($filePath);
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar banco de dados']);
        exit;
    }
    
    // Deletar foto antiga se existir
    if ($oldPhoto && file_exists(__DIR__ . '/../../' . $oldPhoto)) {
        unlink(__DIR__ . '/../../' . $oldPhoto);
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Foto de perfil atualizada com sucesso',
        'profile_picture' => $relativePath
    ]);
    
} catch (PDOException $e) {
    api_error_response('Erro ao atualizar foto de perfil', $e, 500);
} catch (Exception $e) {
    api_error_response('Erro inesperado ao atualizar foto de perfil', $e, 500);
}

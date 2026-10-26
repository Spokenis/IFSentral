<?php
// deletar_projeto.php - Delete a project (Only Manager can delete)

require_once '../config/config.php';
require_once '../core/AuthMiddleware.php';
setupSecureCORS();
require_once '../core/ApiError.php';
require_once '../core/Csrf.php';
require_once '../core/MosquittoSync.php';

use App\Core\AuthMiddleware;
use App\Core\Csrf;

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] != 'DELETE' && $_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use DELETE ou POST.']);
    exit;
}

Csrf::requireValidToken();

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->project_id) || !is_numeric($data->project_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id é obrigatório.']);
    exit;
}

$user_id = AuthMiddleware::requireAuth();

$project_id = intval($data->project_id);

try {
    $conn->beginTransaction();
    
    // Apenas o Gerente do projeto pode deletá-lo (mesma regra usada em
    // alterar_visibilidade_projeto.php, promover_gerente.php, expulsar_participante.php).
    // Antes havia uma checagem extra exigindo perfil global Moderator/Admin,
    // o que impedia um Gerente comum de deletar o próprio projeto.
    if (!AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        $conn->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'Você não tem permissão para deletar este projeto.']);
        exit;
    }

    // Soft delete: marcar como deletado
    $deleteSql = "UPDATE projects SET deletedAt = NOW() WHERE id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$project_id]);

    // Os dispositivos do projeto também saem de uso (como em deletar_dispositivo.php)
    $deviceIdsStmt = $conn->prepare("SELECT id FROM devices WHERE project_id = ? AND deletedAt IS NULL");
    $deviceIdsStmt->execute([$project_id]);
    $deviceIds = $deviceIdsStmt->fetchAll(PDO::FETCH_COLUMN);

    $conn->prepare("UPDATE devices SET deletedAt = NOW() WHERE project_id = ? AND deletedAt IS NULL")->execute([$project_id]);

    if ($deviceIds) {
        $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
        $conn->prepare("UPDATE mqtt_credentials SET enabled = 0 WHERE device_id IN ($placeholders)")->execute($deviceIds);
    }
    
    $conn->commit();

    // Sincroniza o Mosquitto para que as credenciais desativadas parem de valer imediatamente
    if ($deviceIds) {
        try {
            $sync = new MosquittoSync($conn, true);
            $sync->sync();
        } catch (Exception $e) {
            // Não bloqueia a exclusão se a sincronização falhar
        }
    }
    
    http_response_code(200);
    echo json_encode(['message' => 'Projeto deletado com sucesso!']);
    
} catch (Exception $e) {
    if ($conn && $conn->inTransaction()) { $conn->rollBack(); }
    api_error_response('Erro ao deletar projeto', $e, 500);
}
?>

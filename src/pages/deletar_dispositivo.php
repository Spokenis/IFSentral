<?php
// deletar_dispositivo.php - Remove (soft delete) um dispositivo do projeto.
// Só é permitido se o papel do usuário no projeto tiver a flag roles.canDeleteSensor.

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';
require_once '../core/Csrf.php';
require_once '../core/MosquittoSync.php';

use App\Core\Csrf;

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST' && $_SERVER['REQUEST_METHOD'] != 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST ou DELETE.']);
    exit;
}

Csrf::requireValidToken();

$data = json_decode(file_get_contents("php://input"));
$user_id = $_SESSION['user_id'] ?? null;

if (!isset($data->device_id) || !is_numeric($data->device_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'device_id é obrigatório.']);
    exit;
}

$device_id = intval($data->device_id);

try {
    // Verifica se o usuário é membro do projeto do dispositivo e se o papel
    // dele tem permissão de exclusão de sensores (roles.canDeleteSensor).
    $sql = "
        SELECT d.project_id, r.canDeleteSensor
        FROM devices d
        JOIN users_projects up ON up.project_id = d.project_id
        JOIN roles r ON r.id = up.role_id
        WHERE d.id = ? AND up.user_id = ? AND d.deletedAt IS NULL
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$device_id, $user_id]);
    $acesso = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$acesso) {
        http_response_code(404);
        echo json_encode(['error' => 'Dispositivo não encontrado ou você não é membro do projeto.']);
        exit;
    }

    if (!$acesso['canDeleteSensor']) {
        http_response_code(403);
        echo json_encode(['error' => 'Seu papel neste projeto não tem permissão para excluir dispositivos.']);
        exit;
    }

    $conn->beginTransaction();

    $del = $conn->prepare("UPDATE devices SET deletedAt = NOW() WHERE id = ?");
    $del->execute([$device_id]);

    // Desativa credenciais MQTT associadas (o dispositivo não deve mais poder publicar)
    $disable = $conn->prepare("UPDATE mqtt_credentials SET enabled = 0 WHERE device_id = ?");
    $disable->execute([$device_id]);

    $conn->commit();

    // Sincroniza o Mosquitto para que a credencial desativada pare de valer imediatamente
    try {
        $sync = new MosquittoSync($conn, true);
        $sync->sync();
    } catch (Exception $e) {
        // Não bloqueia a exclusão se a sincronização falhar
    }

    echo json_encode([
        'success' => true,
        'message' => 'Dispositivo removido com sucesso.',
        'project_id' => $acesso['project_id'],
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    api_error_response('Erro ao remover dispositivo', $e, 500);
}
?>

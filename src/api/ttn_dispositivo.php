<?php
/**
 * API: Liga um dispositivo do IFSCentral a um end device do The Things Network
 *
 * POST /api/ttn-dispositivo
 * {
 *   "device_id": 12,
 *   "ttn_device_id": "sensor-sala-204"   ("" ou null desliga)
 * }
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/AuthMiddleware.php';
setupSecureCORS();
require_once __DIR__ . '/../core/ApiError.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/TtnClient.php';

use App\Core\AuthMiddleware;
use App\Core\Csrf;
use App\Core\TtnClient;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$user_id = AuthMiddleware::requireAuth();
Csrf::requireValidToken();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $device_id = intval($input['device_id'] ?? 0);
    $ttn_device_id = trim((string)($input['ttn_device_id'] ?? ''));

    if (!$device_id || !AuthMiddleware::hasDeviceAccess($conn, $user_id, $device_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada ou dispositivo não encontrado']);
        exit;
    }

    if ($ttn_device_id !== '' && !TtnClient::isValidId($ttn_device_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID do dispositivo no TTN inválido: use de 3 a 36 letras minúsculas, números e hífens, como aparece no console do TTN.']);
        exit;
    }

    $projectStmt = $conn->prepare("SELECT project_id FROM devices WHERE id = ?");
    $projectStmt->execute([$device_id]);
    $project_id = (int)$projectStmt->fetchColumn();

    if ($ttn_device_id !== '') {
        // Um end device do TTN alimenta um único dispositivo ativo do projeto
        $dupStmt = $conn->prepare("
            SELECT name FROM devices
            WHERE project_id = ? AND ttn_device_id = ? AND id <> ? AND deletedAt IS NULL
            LIMIT 1");
        $dupStmt->execute([$project_id, $ttn_device_id, $device_id]);
        $other = $dupStmt->fetchColumn();
        if ($other !== false) {
            http_response_code(409);
            echo json_encode(['error' => "Este ID do TTN já está ligado ao dispositivo \"$other\" do projeto."]);
            exit;
        }
    }

    $conn->prepare("UPDATE devices SET ttn_device_id = ? WHERE id = ?")
        ->execute([$ttn_device_id !== '' ? $ttn_device_id : null, $device_id]);

    echo json_encode([
        'success' => true,
        'ttn_device_id' => $ttn_device_id !== '' ? $ttn_device_id : null,
        'message' => $ttn_device_id !== '' ? 'Dispositivo ligado ao TTN' : 'Dispositivo desligado do TTN',
    ]);

} catch (PDOException $e) {
    api_error_response('Erro ao salvar o ID do TTN', $e, 500);
}
?>

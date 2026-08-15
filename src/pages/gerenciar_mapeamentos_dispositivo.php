<?php
// gerenciar_mapeamentos_dispositivo.php - CRUD de state_mappings de um dispositivo
// Permite traduzir um valor bruto do payload (ex: "1") em uma descrição legível
// (ex: "Ligado") para um determinado campo (json_key) do dispositivo.

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';
require_once '../core/Csrf.php';

use App\Core\Csrf;

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

$user_id = $_SESSION['user_id'] ?? null;

function verificarAcessoDispositivoMap($conn, $device_id, $user_id)
{
    $stmt = $conn->prepare("
        SELECT 1 FROM devices d
        JOIN users_projects up ON up.project_id = d.project_id
        WHERE d.id = ? AND up.user_id = ? AND d.deletedAt IS NULL
        LIMIT 1
    ");
    $stmt->execute([$device_id, $user_id]);
    return $stmt->rowCount() > 0;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'GET') {
    if (!isset($_GET['device_id']) || !is_numeric($_GET['device_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id é obrigatório.']);
        exit;
    }

    $device_id = intval($_GET['device_id']);

    try {
        if (!verificarAcessoDispositivoMap($conn, $device_id, $user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada.']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT id, json_key, value_read, description
            FROM state_mappings
            WHERE device_id = ?
            ORDER BY json_key ASC, value_read ASC
        ");
        $stmt->execute([$device_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        api_error_response('Erro ao buscar mapeamentos', $e, 500);
    }
    exit;
}

if ($method == 'POST') {
    Csrf::requireValidToken();

    $data = json_decode(file_get_contents("php://input"));

    if (
        !isset($data->device_id) || !is_numeric($data->device_id) ||
        !isset($data->json_key) || trim($data->json_key) === '' ||
        !isset($data->value_read) || trim((string)$data->value_read) === '' ||
        !isset($data->description) || trim($data->description) === ''
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id, json_key, value_read e description são obrigatórios.']);
        exit;
    }

    $device_id = intval($data->device_id);

    try {
        if (!verificarAcessoDispositivoMap($conn, $device_id, $user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada.']);
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO state_mappings (device_id, json_key, value_read, description)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE description = VALUES(description)
        ");
        $stmt->execute([
            $device_id,
            trim($data->json_key),
            trim((string)$data->value_read),
            trim($data->description),
        ]);

        echo json_encode(['success' => true, 'message' => 'Mapeamento salvo com sucesso.']);
    } catch (PDOException $e) {
        api_error_response('Erro ao salvar mapeamento', $e, 500);
    }
    exit;
}

if ($method == 'DELETE') {
    Csrf::requireValidToken();

    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->mapping_id) || !is_numeric($data->mapping_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'mapping_id é obrigatório.']);
        exit;
    }

    $mapping_id = intval($data->mapping_id);

    try {
        // Só permite apagar se o mapeamento pertencer a um dispositivo que o usuário acessa
        $stmt = $conn->prepare("
            SELECT sm.id FROM state_mappings sm
            JOIN devices d ON d.id = sm.device_id
            JOIN users_projects up ON up.project_id = d.project_id
            WHERE sm.id = ? AND up.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$mapping_id, $user_id]);

        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Mapeamento não encontrado ou sem permissão.']);
            exit;
        }

        $del = $conn->prepare("DELETE FROM state_mappings WHERE id = ?");
        $del->execute([$mapping_id]);

        echo json_encode(['success' => true, 'message' => 'Mapeamento removido.']);
    } catch (PDOException $e) {
        api_error_response('Erro ao remover mapeamento', $e, 500);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
?>

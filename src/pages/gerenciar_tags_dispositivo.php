<?php
// gerenciar_tags_dispositivo.php - Lista (GET) ou substitui (POST) as tags de um dispositivo

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

function verificarAcessoDispositivo($conn, $device_id, $user_id)
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

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (!isset($_GET['device_id']) || !is_numeric($_GET['device_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id é obrigatório.']);
        exit;
    }

    $device_id = intval($_GET['device_id']);

    try {
        if (!verificarAcessoDispositivo($conn, $device_id, $user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada.']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT t.id, t.name
            FROM device_tags dt
            JOIN tags t ON t.id = dt.tag_id
            WHERE dt.device_id = ?
            ORDER BY t.name ASC
        ");
        $stmt->execute([$device_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        api_error_response('Erro ao buscar tags do dispositivo', $e, 500);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    Csrf::requireValidToken();

    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->device_id) || !is_numeric($data->device_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id é obrigatório.']);
        exit;
    }

    $device_id = intval($data->device_id);
    $tags = isset($data->tags) ? (array) $data->tags : [];

    try {
        if (!verificarAcessoDispositivo($conn, $device_id, $user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada.']);
            exit;
        }

        $conn->beginTransaction();

        $tag_ids = [];
        $stmt_find_tag = $conn->prepare("SELECT id FROM tags WHERE name = ?");
        $stmt_insert_tag = $conn->prepare("INSERT INTO tags (name) VALUES (?)");

        foreach ($tags as $tag_input) {
            $tag_input = is_string($tag_input) ? trim($tag_input) : $tag_input;
            if ($tag_input === '' || $tag_input === null) {
                continue;
            }

            // Se for numérico, é o ID de uma tag já existente (selecionada no Select2)
            if (is_numeric($tag_input)) {
                $tag_ids[] = intval($tag_input);
                continue;
            }

            $stmt_find_tag->execute([$tag_input]);
            $existing = $stmt_find_tag->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $tag_ids[] = $existing['id'];
            } else {
                $stmt_insert_tag->execute([$tag_input]);
                $tag_ids[] = $conn->lastInsertId();
            }
        }

        $tag_ids = array_unique($tag_ids);

        // Substitui o conjunto de tags do dispositivo pelo novo conjunto enviado
        $del = $conn->prepare("DELETE FROM device_tags WHERE device_id = ?");
        $del->execute([$device_id]);

        $insert = $conn->prepare("INSERT INTO device_tags (device_id, tag_id) VALUES (?, ?)");
        foreach ($tag_ids as $tag_id) {
            $insert->execute([$device_id, $tag_id]);
        }

        $conn->commit();

        echo json_encode(['success' => true, 'message' => 'Tags atualizadas com sucesso.']);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        api_error_response('Erro ao atualizar tags do dispositivo', $e, 500);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
?>

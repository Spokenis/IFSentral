<?php
/**
 * API: Exportar Dados do Projeto
 *
 * GET ?project_id=N[&cursor=...][&per_page=5000]
 *
 * Devolve as leituras do projeto em lotes, em ordem de dispositivo e, dentro
 * de cada um, cronológica. A página do projeto (gerenciar-projeto.php) pede um
 * lote atrás do outro, passando o next_cursor, e monta o CSV/Excel/JSON.
 *
 * Paginação por cursor (dispositivo, instante, id) em vez de OFFSET: com
 * OFFSET o banco relia e descartava todas as linhas anteriores a cada página,
 * e exportar um projeto grande ficava cada vez mais lento. Cada lote agora é
 * uma leitura de faixa no índice (device_id, created_at).
 *
 * Cada linha traz os metadados (id, device_id, device_name, timestamp) e a
 * leitura original em "payload": antes os campos do payload eram copiados para
 * o mesmo nível e um campo "id" ou "timestamp" enviado pelo dispositivo
 * sobrescrevia o da plataforma.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/AuthMiddleware.php';
setupSecureCORS();
require_once __DIR__ . '/../core/ApiError.php';

use App\Core\AuthMiddleware;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    if (!isset($_GET['project_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id é obrigatório']);
        exit;
    }

    $project_id = intval($_GET['project_id']);
    $user_id = AuthMiddleware::requireAuth();

    if (!AuthMiddleware::hasProjectAccess($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada']);
        exit;
    }

    $perPage = max(1, min(5000, intval($_GET['per_page'] ?? 5000)));

    // Cursor: "device_id|created_at|id" da última linha do lote anterior
    $cursor = null;
    if (!empty($_GET['cursor'])) {
        $partes = explode('|', (string) $_GET['cursor']);
        if (count($partes) !== 3 || !ctype_digit($partes[0]) || !ctype_digit($partes[2])
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $partes[1])) {
            http_response_code(400);
            echo json_encode(['error' => 'cursor inválido']);
            exit;
        }
        $cursor = ['device_id' => (int) $partes[0], 'created_at' => $partes[1], 'id' => (int) $partes[2]];
    }

    $projectStmt = $conn->prepare("SELECT name, createdAt FROM projects WHERE id = ?");
    $projectStmt->execute([$project_id]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

    $devicesStmt = $conn->prepare("SELECT id, name FROM devices WHERE project_id = ? ORDER BY id");
    $devicesStmt->execute([$project_id]);
    $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);
    $nomes = array_column($devices, 'name', 'id');

    // O total só no primeiro lote (antes era recontado a cada página)
    $total = null;
    if ($cursor === null && $devices) {
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) FROM device_payloads WHERE device_id IN ("
            . implode(',', array_fill(0, count($devices), '?')) . ")"
        );
        $countStmt->execute(array_column($devices, 'id'));
        $total = (int) $countStmt->fetchColumn();
    }

    // Um dispositivo por vez, continuando de onde o lote anterior parou
    $linhas = [];
    $porDispositivo = $conn->prepare(
        "SELECT id, device_id, payload, created_at FROM device_payloads
         WHERE device_id = ? AND (created_at > ? OR (created_at = ? AND id > ?))
         ORDER BY created_at, id
         LIMIT ?"
    );
    foreach ($devices as $device) {
        $did = (int) $device['id'];
        if ($cursor !== null && $did < $cursor['device_id']) {
            continue;
        }
        $desdeInstante = '1970-01-01 00:00:00';
        $desdeId = 0;
        if ($cursor !== null && $did === $cursor['device_id']) {
            $desdeInstante = $cursor['created_at'];
            $desdeId = $cursor['id'];
        }
        $falta = $perPage - count($linhas);
        $porDispositivo->bindValue(1, $did, PDO::PARAM_INT);
        $porDispositivo->bindValue(2, $desdeInstante);
        $porDispositivo->bindValue(3, $desdeInstante);
        $porDispositivo->bindValue(4, $desdeId, PDO::PARAM_INT);
        $porDispositivo->bindValue(5, $falta + 1, PDO::PARAM_INT); // +1: sabe se o dispositivo tem mais
        $porDispositivo->execute();
        foreach ($porDispositivo->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $linhas[] = $l;
        }
        if (count($linhas) > $perPage) {
            break;
        }
    }

    $temMais = count($linhas) > $perPage;
    $linhas = array_slice($linhas, 0, $perPage);

    $data = [];
    foreach ($linhas as $l) {
        $payload = json_decode($l['payload'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }
        $data[] = [
            'id' => (int) $l['id'],
            'device_id' => (int) $l['device_id'],
            'device_name' => $nomes[$l['device_id']] ?? null,
            'timestamp' => $l['created_at'],
            'payload' => $payload,
        ];
    }

    $proximo = null;
    if ($temMais && $linhas) {
        $u = end($linhas);
        $proximo = $u['device_id'] . '|' . $u['created_at'] . '|' . $u['id'];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'project' => $project,
        'devices' => $devices,
        'data' => $data,
        'total_records' => count($data),
        'pagination' => [
            'per_page' => $perPage,
            'total' => $total,          // só no primeiro lote
            'next_cursor' => $proximo,  // null = acabou
        ],
    ]);

} catch (PDOException $e) {
    api_error_response('Erro ao exportar dados', $e, 500);
}

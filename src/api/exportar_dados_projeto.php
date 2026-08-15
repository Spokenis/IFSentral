<?php
/**
 * API: Exportar Dados do Projeto
 * Retorna todos os payloads do projeto formatados
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

    // Validar se o usuário tem acesso ao projeto
    if (!AuthMiddleware::hasProjectAccess($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada']);
        exit;
    }

    // Buscar informações do projeto
    $projectSql = "SELECT name, createdAt FROM projects WHERE id = ?";
    $projectStmt = $conn->prepare($projectSql);
    $projectStmt->execute([$project_id]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

    // Buscar todos os dispositivos do projeto
    $devicesSql = "SELECT id, name FROM devices WHERE project_id = ? ORDER BY name";
    $devicesStmt = $conn->prepare($devicesSql);
    $devicesStmt->execute([$project_id]);
    $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Paginado em vez de um LIMIT 10000 fixo: antes, um projeto com mais de
    // 10 mil payloads tinha a exportação truncada silenciosamente, sem
    // nenhum aviso — o frontend agora busca todas as páginas e concatena
    // antes de gerar o arquivo (ver gerenciar-projeto.php).
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = max(1, min(5000, intval($_GET['per_page'] ?? 5000)));
    $offset = ($page - 1) * $perPage;

    $countSql = "
        SELECT COUNT(*) FROM device_payloads dp
        JOIN devices d ON dp.device_id = d.id
        WHERE d.project_id = ?
    ";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute([$project_id]);
    $total = (int) $countStmt->fetchColumn();

    // dp.id como desempate: created_at sozinho pode empatar entre linhas sob
    // alta frequência de ingestão, o que quebraria a paginação (linhas
    // duplicadas ou puladas entre páginas).
    $payloadsSql = "
        SELECT
            dp.id,
            dp.device_id,
            d.name as device_name,
            dp.payload,
            dp.created_at
        FROM device_payloads dp
        JOIN devices d ON dp.device_id = d.id
        WHERE d.project_id = ?
        ORDER BY dp.created_at DESC, dp.id DESC
        LIMIT ? OFFSET ?
    ";

    $payloadsStmt = $conn->prepare($payloadsSql);
    $payloadsStmt->bindValue(1, $project_id, PDO::PARAM_INT);
    $payloadsStmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $payloadsStmt->bindValue(3, $offset, PDO::PARAM_INT);
    $payloadsStmt->execute();
    $payloads = $payloadsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Processar payloads para formato estruturado
    $processedData = [];
    foreach ($payloads as $payload) {
        $payloadData = json_decode($payload['payload'], true);

        // json_decode nunca lança exceção em JSON inválido (retorna null) —
        // checar json_last_error() é a forma correta de detectar isso.
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payloadData)) {
            continue;
        }

        $row = [
            'id' => $payload['id'],
            'device_id' => $payload['device_id'],
            'device_name' => $payload['device_name'],
            'timestamp' => $payload['created_at']
        ];

        // Adicionar campos do JSON do payload
        foreach ($payloadData as $key => $value) {
            $row[$key] = $value;
        }

        $processedData[] = $row;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'project' => $project,
        'devices' => $devices,
        'data' => $processedData,
        'total_records' => count($processedData),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ],
    ]);

} catch (PDOException $e) {
    api_error_response('Erro ao exportar dados', $e, 500);
}
?>

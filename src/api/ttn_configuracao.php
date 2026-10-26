<?php
/**
 * API: Integração do projeto com o The Things Network (Storage Integration)
 *
 * GET    /api/ttn-configuracao?project_id=1   (membro)   -> configuração e status (sem a chave)
 * POST   /api/ttn-configuracao                (gerente)  -> salva; testa a chave no TTN antes
 * {
 *   "project_id": 1,
 *   "cluster": "eu1.cloud.thethings.network",
 *   "application_id": "minha-aplicacao",
 *   "api_key": "NNSXS....",          (opcional ao alterar: mantém a atual)
 *   "enabled": 1
 * }
 * DELETE /api/ttn-configuracao {"project_id": 1}  (gerente) -> remove a integração
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/AuthMiddleware.php';
setupSecureCORS();
require_once __DIR__ . '/../core/ApiError.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/TtnClient.php';
require_once __DIR__ . '/../core/TtnSync.php';

use App\Core\AuthMiddleware;
use App\Core\Csrf;
use App\Core\TtnClient;
use App\Core\TtnSync;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$user_id = AuthMiddleware::requireAuth();

try {
    if ($method === 'GET') {
        $project_id = intval($_GET['project_id'] ?? 0);
        if (!$project_id || !AuthMiddleware::hasProjectAccess($conn, $user_id, $project_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada ou projeto não encontrado']);
            exit;
        }

        $app = (new TtnSync($conn))->findApplication($project_id);

        // Horário com fuso explícito: o MySQL devolve last_sync_at sem fuso (no
        // fuso da sessão), e o navegador o leria como horário local
        $syncStmt = $conn->prepare("SELECT UNIX_TIMESTAMP(last_sync_at) FROM ttn_applications WHERE project_id = ?");
        $syncStmt->execute([$project_id]);
        $lastSyncUnix = $syncStmt->fetchColumn();

        $devicesStmt = $conn->prepare("SELECT id, name, ttn_device_id FROM devices WHERE project_id = ? AND deletedAt IS NULL ORDER BY name");
        $devicesStmt->execute([$project_id]);

        echo json_encode([
            'configured' => $app !== null,
            'cluster' => $app['cluster'] ?? null,
            'application_id' => $app['application_id'] ?? null,
            'enabled' => $app ? (bool)$app['enabled'] : false,
            'api_key_set' => $app !== null,
            'last_sync_at' => $lastSyncUnix ? gmdate('Y-m-d\\TH:i:s\\Z', (int)$lastSyncUnix) : null,
            'last_received_at' => $app['last_received_at'] ?? null,
            'last_error' => $app['last_error'] ?? null,
            'is_manager' => AuthMiddleware::isProjectManager($conn, $user_id, $project_id),
            'clusters' => TtnClient::CLUSTERS,
            'devices' => $devicesStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit;
    }

    Csrf::requireValidToken();

    $input = json_decode(file_get_contents('php://input'), true);
    $project_id = intval($input['project_id'] ?? 0);

    if (!$project_id || !AuthMiddleware::isProjectManager($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas o gerente do projeto pode configurar a integração com o TTN']);
        exit;
    }

    if ($method === 'DELETE') {
        $conn->prepare("DELETE FROM ttn_applications WHERE project_id = ?")->execute([$project_id]);
        echo json_encode(['success' => true, 'message' => 'Integração com o TTN removida']);
        exit;
    }

    // POST: salvar
    $cluster = trim((string)($input['cluster'] ?? ''));
    $application_id = trim((string)($input['application_id'] ?? ''));
    $api_key = trim((string)($input['api_key'] ?? ''));
    $enabled = !empty($input['enabled']) ? 1 : 0;

    if (!TtnClient::isValidCluster($cluster)) {
        http_response_code(400);
        echo json_encode(['error' => 'Escolha um dos clusters do TTN (eu1, nam1 ou au1).']);
        exit;
    }
    if (!TtnClient::isValidId($application_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da aplicação inválido: use de 3 a 36 letras minúsculas, números e hífens, como aparece no console do TTN.']);
        exit;
    }

    $sync = new TtnSync($conn);
    $current = $sync->findApplication($project_id);

    if ($api_key === '') {
        if (!$current) {
            http_response_code(400);
            echo json_encode(['error' => 'Informe a chave de API do TTN.']);
            exit;
        }
        $api_key = $current['api_key'];
    } elseif (!preg_match('/^[A-Za-z0-9._-]{16,255}$/', $api_key)) {
        http_response_code(400);
        echo json_encode(['error' => 'Chave de API inválida: copie a chave inteira gerada no console do TTN (começa com NNSXS.).']);
        exit;
    }

    // Testa as credenciais antes de gravar
    try {
        TtnClient::fetchStoredUplinks($cluster, $application_id, $api_key, null, 1);
    } catch (\RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Não foi possível acessar o TTN: ' . $e->getMessage()]);
        exit;
    }

    if ($current) {
        // Outra aplicação: recomeça a busca do início da retenção do TTN
        $sameApplication = $current['cluster'] === $cluster && $current['application_id'] === $application_id;
        $stmt = $conn->prepare("
            UPDATE ttn_applications
            SET cluster = ?, application_id = ?, api_key = ?, enabled = ?, last_error = NULL,
                last_received_at = IF(?, last_received_at, NULL),
                last_sync_at = IF(?, last_sync_at, NULL)
            WHERE project_id = ?");
        $stmt->execute([$cluster, $application_id, $api_key, $enabled, $sameApplication ? 1 : 0, $sameApplication ? 1 : 0, $project_id]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO ttn_applications (project_id, cluster, application_id, api_key, enabled)
            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$project_id, $cluster, $application_id, $api_key, $enabled]);
    }

    echo json_encode(['success' => true, 'message' => 'Integração com o TTN salva']);

} catch (PDOException $e) {
    api_error_response('Erro ao salvar a integração com o TTN', $e, 500);
}
?>

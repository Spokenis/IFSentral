<?php
/**
 * API: Busca no The Things Network os uplinks novos (Storage Integration)
 *
 * POST /api/ttn-sincronizar
 *   sem corpo                -> busca as aplicações cuja última busca tem mais
 *                               de 1 minuto. Não exige login: no Lite, as páginas
 *                               chamam isto a cada acesso, inclusive a inicial,
 *                               e é o que mantém os dados do TTN em dia sem
 *                               processo em segundo plano no servidor.
 *   {"project_id": 1}        -> (membro do projeto) busca agora a aplicação do
 *                               projeto e devolve o resultado
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/AuthMiddleware.php';
setupSecureCORS();
require_once __DIR__ . '/../core/ApiError.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/TtnSync.php';

use App\Core\AuthMiddleware;
use App\Core\Csrf;
use App\Core\TtnSync;

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

// A busca continua mesmo se o usuário sair da página antes do fim
ignore_user_abort(true);
set_time_limit(120);

$input = json_decode(file_get_contents('php://input'), true);
$project_id = intval($input['project_id'] ?? 0);

try {
    $sync = new TtnSync($conn);

    if (!$project_id) {
        $results = $sync->syncDue();
        echo json_encode(['applications' => count($results)]);
        exit;
    }

    $user_id = AuthMiddleware::requireAuth();
    Csrf::requireValidToken();

    if (!AuthMiddleware::hasProjectAccess($conn, $user_id, $project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada ou projeto não encontrado']);
        exit;
    }

    $app = $sync->findApplication($project_id);
    if (!$app) {
        http_response_code(404);
        echo json_encode(['error' => 'O projeto não tem integração com o TTN configurada']);
        exit;
    }

    $result = $sync->syncApplication($app);
    if (isset($result['error'])) {
        http_response_code(502);
        echo json_encode(['error' => $result['error']]);
        exit;
    }
    if (!empty($result['skipped'])) {
        echo json_encode(['message' => 'Já há uma busca em andamento para este projeto']);
        exit;
    }

    echo json_encode(['success' => true] + $result);

} catch (PDOException $e) {
    api_error_response('Erro ao sincronizar com o TTN', $e, 500);
}
?>

<?php
/**
 * obter_notificacoes.php - Agrega itens pendentes que precisam da atenção do
 * usuário logado: convites recebidos, solicitações de participação nos
 * projetos que ele gerencia e (se Admin) solicitações de promoção a Moderador.
 */

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

$identifier = $_SESSION['user_id'] ?? $_SESSION['email'] ?? null;
$column = isset($_SESSION['user_id']) ? 'id' : 'email';

try {
    $stmtUser = $conn->prepare("SELECT id, email, profile FROM users WHERE $column = ? AND deletedAt IS NULL");
    $stmtUser->execute([$identifier]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não encontrado.']);
        exit;
    }

    $user_id = $user['id'];
    $items = [];

    // 1. Convites de projeto recebidos pelo usuário
    $sqlConvites = "
        SELECT i.id, i.created_at, p.name as project_name
        FROM invitations i
        JOIN projects p ON i.project_id = p.id
        WHERE ((i.invited_user_id = ? AND i.status = 'pending')
               OR (i.invited_email = ? AND i.status = 'pending'))
          AND i.expires_at > NOW()
        ORDER BY i.created_at DESC
    ";
    $stmt = $conn->prepare($sqlConvites);
    $stmt->execute([$user_id, $user['email']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'type' => 'convite',
            'title' => 'Convite para o projeto "' . $row['project_name'] . '"',
            'link' => '/perfil',
            'created_at' => $row['created_at'],
        ];
    }

    // 2. Solicitações de participação pendentes nos projetos que o usuário gerencia
    $sqlSolicitacoes = "
        SELECT pjr.id, pjr.createdAt, p.id as project_id, p.name as project_name, u.name as requester_name
        FROM project_join_requests pjr
        JOIN projects p ON pjr.project_id = p.id
        JOIN users_projects up ON up.project_id = p.id AND up.user_id = ?
        JOIN roles r ON up.role_id = r.id AND r.name = 'Gerente'
        LEFT JOIN users u ON pjr.user_id = u.id
        WHERE pjr.status = 'pendente'
        ORDER BY pjr.createdAt DESC
    ";
    $stmt = $conn->prepare($sqlSolicitacoes);
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'type' => 'solicitacao_participacao',
            'title' => ($row['requester_name'] ?? 'Alguém') . ' quer participar de "' . $row['project_name'] . '"',
            'link' => '/projeto?id=' . $row['project_id'],
            'created_at' => $row['createdAt'],
        ];
    }

    // 3. Solicitações de promoção a Moderador (apenas visível para Admin)
    if ($user['profile'] === 'Admin') {
        $stmt = $conn->query("
            SELECT pr.id, pr.createdAt, u.name as requester_name
            FROM profile_requests pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.status = 'pendente'
            ORDER BY pr.createdAt DESC
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'type' => 'solicitacao_perfil',
                'title' => ($row['requester_name'] ?? 'Alguém') . ' solicitou privilégios de Moderador',
                'link' => '/admin/usuarios',
                'created_at' => $row['createdAt'],
            ];
        }
    }

    // Mais recentes primeiro, limitado a 30 itens no sino
    usort($items, function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    $items = array_slice($items, 0, 30);

    http_response_code(200);
    echo json_encode([
        'total' => count($items),
        'items' => $items,
    ]);

} catch (PDOException $e) {
    api_error_response('Erro ao buscar notificações', $e, 500);
}
?>

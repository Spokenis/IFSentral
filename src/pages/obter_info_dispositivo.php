<?php
// obter_info_dispositivo.php
// Retorna informações seguras do dispositivo (incluindo API key, mas apenas para o dono)

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

require '../config/db.php';
require '../auth/auth_check.php'; // Validar autenticação

if (!isset($_GET['device_id']) || !is_numeric($_GET['device_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'device_id é obrigatório.']);
    exit;
}

$device_id = intval($_GET['device_id']);

// Obter user_id da sessão ou do banco de dados basado no email
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id && isset($_SESSION['email'])) {
    try {
        $sql_user = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->execute([$_SESSION['email']]);
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $user_id = $user_data['id'];
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Erro ao validar usuário']);
        exit;
    }
}

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

try {
    // Validar se o usuário tem acesso ao dispositivo (é membro do projeto)
    $sql = "
        SELECT 1 FROM devices d
        JOIN users_projects up ON d.project_id = up.project_id
        WHERE d.id = ? AND up.user_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$device_id, $user_id]);
    
    if ($stmt->rowCount() == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada.']);
        exit;
    }
    
    // Buscar dados do dispositivo (com api_key e MQTT credentials)
    $sql = "
        SELECT 
            d.id,
            d.name,
            d.description,
            d.api_key,
            d.createdAt,
            d.project_id,
            p.name AS project_name,
            u.username AS user_username,
            mc.mqtt_username,
            mc.enabled as mqtt_enabled
        FROM 
            devices d
        JOIN 
            projects p ON d.project_id = p.id
        JOIN 
            users u ON d.user_id = u.id
        LEFT JOIN 
            mqtt_credentials mc ON d.id = mc.device_id AND mc.enabled = 1
        WHERE 
            d.id = ? AND d.deletedAt IS NULL
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        http_response_code(404);
        echo json_encode(['error' => 'Dispositivo não encontrado.']);
        exit;
    }
    
    echo json_encode($device);

} catch (PDOException $e) {
    http_response_code(500);
    if (APP_ENV === 'production') {
        echo json_encode(['error' => 'Erro ao buscar dispositivo.']);
    } else {
        echo json_encode(['error' => 'Erro ao buscar dispositivo: ' . $e->getMessage()]);
    }
}
?>

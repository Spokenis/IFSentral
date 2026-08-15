<?php
/**
 * regenerate_mqtt_password.php
 * Gera uma nova senha MQTT para o dispositivo (mesmo username) e sincroniza
 * com o broker Mosquitto. É o endpoint que get_mqtt_credentials.php aponta
 * quando não consegue recuperar a senha atual (nem do banco, nem do backup).
 *
 * Headers Requeridos:
 * X-Api-Key: sua_api_key_aqui
 *
 * Retorna:
 * {
 *   "mqtt_username": "mqdev_abc123...",
 *   "mqtt_password": "...",  // só aparece aqui, uma vez — guarde
 *   "sync_status": "synchronized"
 * }
 */

require_once '../config/config.php';
require_once '../core/MosquittoSync.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

require '../config/db.php';

// ===== VALIDAÇÃO DE SEGURANÇA (mesmo padrão de get_mqtt_credentials.php) =====
$api_key = null;
$headers = getallheaders();
if (isset($headers['X-Api-Key'])) {
    $api_key = trim($headers['X-Api-Key']);
} elseif (isset($_SERVER['HTTP_X_API_KEY'])) {
    $api_key = trim($_SERVER['HTTP_X_API_KEY']);
}

if (empty($api_key)) {
    http_response_code(401);
    echo json_encode(['error' => 'API Key obrigatória no header X-Api-Key']);
    exit;
}

if (strlen($api_key) < 32) {
    http_response_code(400);
    echo json_encode(['error' => 'API Key inválida']);
    exit;
}

try {
    $sql = "SELECT id as device_id FROM devices WHERE api_key = ? AND deletedAt IS NULL";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$api_key]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        http_response_code(401);
        echo json_encode(['error' => 'API Key inválida ou dispositivo não encontrado.']);
        exit;
    }

    $device_id = $device['device_id'];

    // Username baseado num hash da API key — mesmo esquema usado na criação
    // do dispositivo (cadastrar_device.php), mantém-se estável entre
    // regenerações (só a senha muda).
    $key_hash = substr(hash('sha256', $api_key), 0, 16);
    $username = "mqdev_" . $key_hash;

    $password = bin2hex(random_bytes(12)); // 24 caracteres

    // Hash PBKDF2 no formato que o Mosquitto espera (mesmo gerador usado em
    // cadastrar_device.php e generate_mqtt_credentials.php)
    $salt = random_bytes(12);
    $hash = hash_pbkdf2('sha512', $password, $salt, 101, 64, true);
    $password_hash = sprintf('$7$%d$%s$%s', 101, base64_encode($salt), base64_encode($hash));

    $conn->beginTransaction();

    $upsert = $conn->prepare("
        INSERT INTO mqtt_credentials (device_id, mqtt_username, mqtt_password, mqtt_password_hash, enabled)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            mqtt_username = VALUES(mqtt_username),
            mqtt_password = VALUES(mqtt_password),
            mqtt_password_hash = VALUES(mqtt_password_hash),
            enabled = 1,
            updated_at = NOW()
    ");
    $upsert->execute([$device_id, $username, $password, $password_hash]);

    $conn->commit();

    // Sincroniza o Mosquitto pra que a nova senha valha imediatamente
    $syncStatus = 'pending';
    try {
        $sync = new MosquittoSync($conn, true);
        $result = $sync->sync();
        $syncStatus = ($result['success'] ?? false) ? 'synchronized' : 'pending';
    } catch (Exception $e) {
        // Credencial já foi salva no banco; sincronização pode ser refeita depois
    }

    echo json_encode([
        'mqtt_username' => $username,
        'mqtt_password' => $password,
        'sync_status' => $syncStatus,
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    if (APP_ENV === 'production') {
        echo json_encode(['error' => 'Erro ao regenerar credenciais MQTT.']);
    } else {
        echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
    }
}
?>

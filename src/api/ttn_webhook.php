<?php
/**
 * ttn_webhook.php - Webhook para receber dados do The Things Network
 * Refatorado para usar PayloadHandler (compartilhado com MQTT)
 */

require_once '../config/config.php';
require_once '../core/PayloadHandler.php';
require_once '../core/RateLimiter.php';
setupSecureCORS();

use App\Core\PayloadHandler;
use App\Core\RateLimiter;

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

// Inicializa RateLimiter
$rateLimiter = new RateLimiter($conn);

// Validação: Device ID na URL
if (!isset($_GET['device_id']) || !is_numeric($_GET['device_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'device_id é obrigatório na URL e deve ser numérico.']);
    exit;
}
$device_id = intval($_GET['device_id']);

// Validação: API Key no Header
$headers = getallheaders();
$api_key = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? null;

if ($api_key === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Chave de API (X-Api-Key) não fornecida no header.']);
    exit;
}

// ===== PROTEÇÃO DoS: Leitura e validação de tamanho =====
$rawData = file_get_contents("php://input");

// Limite de 8KB para o TTN devido ao volume de metadados da rede LoRaWAN
if (strlen($rawData) > 8192) {
    http_response_code(413); // Payload Too Large
    echo json_encode(['error' => 'O payload excede o tamanho máximo permitido (8KB).']);
    exit;
}

// Parse do JSON enviado pelo TTN
$data = json_decode($rawData);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido ou malformado.']);
    exit;
}
// =========================================================

if (!isset($data->uplink_message) || !isset($data->uplink_message->decoded_payload)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Formato de JSON do TTN inválido ou "decoded_payload" ausente.',
        'note' => 'Certifique-se de que seu dispositivo no TTN tenha um "Payload Formatter" ativado.'
    ]);
    exit;
}

// Extrai o payload real (dados do sensor)
$payload_real = $data->uplink_message->decoded_payload;

// Verifica rate limit ANTES de processar
$rateCheck = $rateLimiter->checkLimit($device_id, 'ttn', $_SERVER['REMOTE_ADDR']);

if (!$rateCheck['allowed']) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Rate limit excedido',
        'retry_after' => 60,
        'rate_limit' => [
            'requests' => $rateCheck['requests'],
            'limit' => $rateCheck['limit'],
            'remaining' => $rateCheck['remaining']
        ]
    ]);
    exit;
}

try {
    // Usa PayloadHandler para salvar (com validação e autenticação integrada)
    $handler = new PayloadHandler($conn);
    $result = $handler->savePayload($device_id, $api_key, $payload_real, 'ttn');

    if ($result['success']) {
        http_response_code(201);
        echo json_encode([
            'message' => 'Payload do TTN recebido e salvo com sucesso!',
            'payload_id' => $result['id'],
            'device_id' => $result['device_id'],
            'project_id' => $result['project_id'],
            'source' => $result['source'],
            'payload_data' => $payload_real,
            'rate_limit' => [
                'requests' => $rateCheck['requests'],
                'limit' => $rateCheck['limit'],
                'remaining' => $rateCheck['remaining']
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => $result['message']]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar payload: ' . $e->getMessage()]);
}
?>
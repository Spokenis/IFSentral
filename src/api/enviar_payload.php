<?php
/**
 * enviar_payload.php - API para enviar payloads de dispositivos
 * Refatorado para usar PayloadHandler (compartilhado com TTN)
 * Agora com Rate Limiting configurável e Proteção DoS
 */

require_once '../config/config.php';
require_once '../core/PayloadHandler.php';
require_once '../core/RateLimiter.php';
require_once '../core/AuthMiddleware.php';
require_once '../core/ApiError.php';
setupSecureCORS();

use App\Core\PayloadHandler;
use App\Core\RateLimiter;
use App\Core\AuthMiddleware;

header("Content-Type: application/json; charset=UTF-8");

// CORS OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas POST permitido
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

require '../config/db.php';

$deviceAuth = AuthMiddleware::validateApiKey($conn);
if (!$deviceAuth) {
    http_response_code(401);
    echo json_encode(['error' => 'Chave de API inválida ou não fornecida no header.']);
    exit;
}

// ===== PROTEÇÃO DoS: Leitura e validação de tamanho =====
$rawData = file_get_contents("php://input");

// Limite rígido de 2KB (2048 bytes) para o payload
if (strlen($rawData) > 2048) {
    http_response_code(413); // Payload Too Large
    echo json_encode(['error' => 'O payload excede o tamanho máximo permitido (2KB).']);
    exit;
}

// Parse do JSON recebido
$data = json_decode($rawData);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido ou malformado.']);
    exit;
}
// =========================================================

// Validação: Device ID e Payload obrigatórios
if (!isset($data->device_id) || !is_numeric($data->device_id) || !isset($data->payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'device_id (numérico) e payload (objeto JSON) são obrigatórios.']);
    exit;
}

// Campos opcionais para leituras guardadas pelo dispositivo enquanto estava
// sem rede: "timestamp" (instante da medição, segundos Unix) e "id"
// (identificador da leitura; reenviar o mesmo id não grava de novo).
$instanteMedicao = null;
if (isset($data->timestamp)) {
    if (!is_numeric($data->timestamp) || (int) $data->timestamp < time() - 7 * 86400 || (int) $data->timestamp > time() + 300) {
        http_response_code(400);
        echo json_encode(['error' => 'timestamp deve ser o instante da medição em segundos Unix, de até 7 dias atrás.']);
        exit;
    }
    $instanteMedicao = (int) $data->timestamp;
}
$idLeitura = null;
if (isset($data->id)) {
    if (!is_string($data->id) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $data->id)) {
        http_response_code(400);
        echo json_encode(['error' => 'id deve ter de 1 a 100 caracteres: letras, números, _ . : -']);
        exit;
    }
    $idLeitura = $data->id;
}

$requestedDeviceId = intval($data->device_id);
if (intval($deviceAuth['id']) !== $requestedDeviceId) {
    http_response_code(403);
    echo json_encode(['error' => 'A Chave de API não pertence ao device informado.']);
    exit;
}

try {
    // Inicializa Rate Limiter
    $rateLimiter = new RateLimiter($conn);
    
    // Verifica rate limit ANTES de qualquer coisa
    $rateCheck = $rateLimiter->checkLimit(
        $requestedDeviceId,
        'http', 
        $_SERVER['REMOTE_ADDR'] ?? null
    );

    if (!$rateCheck['allowed']) {
        http_response_code(429);  // Too Many Requests
        echo json_encode([
            'error' => 'Rate limit excedido',
            'message' => $rateCheck['reason'],
            'requests' => $rateCheck['requests'],
            'limit' => $rateCheck['limit'],
            'reset_at' => date('Y-m-d H:i:s', strtotime('+1 minute')),
            'retry_after' => 60  // segundos
        ]);
        exit;
    }

    // Usa PayloadHandler para salvar (com validação e autenticação integrada)
    $handler = new PayloadHandler($conn);
    
    // Valida o payload primeiro
    $validation = $handler->validatePayload($data->payload, $data->device_id);
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Payload inválido',
            'errors' => $validation['errors']
        ]);
        exit;
    }

    // Salva o payload
    $result = $handler->savePayload($requestedDeviceId, $deviceAuth['api_key'], $data->payload, 'http', $instanteMedicao, $idLeitura);

    if ($result['success'] && !empty($result['duplicate'])) {
        // Mesmo id já gravado (reenvio depois de uma resposta perdida): sucesso, sem gravar de novo
        http_response_code(200);
        echo json_encode(['message' => 'Leitura já recebida antes; não foi gravada de novo.', 'duplicate' => true]);
    } elseif ($result['success']) {
        http_response_code(201);
        echo json_encode([
            'message' => 'Payload salvo com sucesso!',
            'payload_id' => $result['id'],
            'device_id' => $result['device_id'],
            'project_id' => $result['project_id'],
            'source' => $result['source'],
            'rate_limit' => [
                'requests' => $rateCheck['requests'] + 1,  // Inclui esta requisição
                'limit' => $rateCheck['limit'],
                'remaining' => $rateCheck['remaining'] - 1
            ]
        ]);
    } elseif (!empty($result['erro_transitorio'])) {
        // Problema do servidor (banco fora, disco cheio): o dispositivo deve
        // tentar de novo. Antes respondia 401, e o firmware entendia que a
        // chave de API era inválida.
        http_response_code(503);
        header('Retry-After: 10');
        echo json_encode(['error' => 'Servidor temporariamente sem conseguir gravar. Tente de novo em instantes.']);
    } else {
        http_response_code(401);
        echo json_encode(['error' => $result['message']]);
    }

} catch (Exception $e) {
    api_error_response('Erro ao salvar payload', $e, 500);
}
?>
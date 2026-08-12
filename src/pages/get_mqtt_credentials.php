<?php
/**
 * get_mqtt_credentials.php
 * Retorna credenciais MQTT do dispositivo
 * * SEGURO: Usa API Key como identificador (não sequencial)
 * * Headers Requeridos:
 * X-Api-Key: sua_api_key_aqui
 * * Retorna:
 * {
 * "mqtt_username": "mqdev_abc123...",
 * "sync_status": "synchronized"
 * // "mqtt_password": "..." (Apenas se ?reveal=true for passado)
 * }
 * * ⚠️ A senha é armazenada em plain text APENAS no arquivo de backup (chmod 600)
 * ⚠️ Segurança: Usa API Key (não sequencial) para evitar enumeração de recursos
 */

require_once '../config/config.php';
setupSecureCORS();

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

require '../config/db.php';

// ===== VALIDAÇÃO DE SEGURANÇA =====
// Validate API Key from header (não da URL)
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
    // ===== BUSCAR DEVICE PELA API KEY =====
    // API Key é única, não sequencial - melhor para segurança
    $sql = "
        SELECT 
            d.id as device_id,
            d.project_id,
            d.api_key
        FROM devices d
        WHERE d.api_key = ? AND d.deletedAt IS NULL
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$api_key]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        http_response_code(401);
        echo json_encode(['error' => 'API Key inválida ou dispositivo não encontrado.']);
        exit;
    }
    
    $device_id = $device['device_id'];
    
    // ===== BUSCAR CREDENCIAIS MQTT =====
    $sql = "
        SELECT 
            mqtt_username,
            mqtt_password,
            enabled
        FROM mqtt_credentials
        WHERE device_id = ? AND enabled = 1
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$device_id]);
    $mqtt_creds = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mqtt_creds) {
        http_response_code(404);
        echo json_encode(['error' => 'Credenciais MQTT não encontradas para este dispositivo.']);
        exit;
    }
    
    if (empty($mqtt_creds['mqtt_password'])) {
        // Se não tem senha no BD, tenta buscar do arquivo de backup (fallback)
        $mqtt_password = null;
        $backup_files = [
            __DIR__ . '/../../mqtt_credentials_auto.txt',
            __DIR__ . '/../../mqtt_credentials_BACKUP_SEGURO.txt',
            __DIR__ . '/../../mqtt_credentials_backup.txt'
        ];
        
        foreach ($backup_files as $backup_file) {
            if (file_exists($backup_file) && is_readable($backup_file)) {
                $backup_contents = file_get_contents($backup_file);
                
                // Regex mais tolerante:
                // 1. (?i) faz case-insensitive
                // 2. \s* captura qualquer espaço ou tabulação
                // 3. [\r\n]+ garante que pegamos a quebra de linha independente do SO
                $pattern = '/Username:\s*' . preg_quote($mqtt_creds['mqtt_username'], '/') . '\s*[\r\n]+Password:\s*(\S+)/i';
                
                if (preg_match($pattern, $backup_contents, $matches)) {
                    $mqtt_password = trim($matches[1]);
                    
                    // Atualiza o BD para não precisar ler arquivo novamente
                    try {
                        $update_sql = "UPDATE mqtt_credentials SET mqtt_password = ? WHERE device_id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->execute([$mqtt_password, $device_id]);
                    } catch (Exception $e) {
                        // Logar erro se necessário
                    }
                    
                    break;
                }
            }
        }
        
        if (!$mqtt_password) {
            http_response_code(424);
            echo json_encode([
                'error' => 'Senha MQTT não encontrada. Você pode regenerá-la.',
                'mqtt_username' => $mqtt_creds['mqtt_username'],
                'regenerate_needed' => true,
                'regenerate_url' => 'regenerate_mqtt_password.php'
            ]);
            exit;
        }
        
        $mqtt_creds['mqtt_password'] = $mqtt_password;
    }
    
    // ===== RESPOSTA COM SUCESSO =====
    $revealPassword = isset($_GET['reveal']) && $_GET['reveal'] === 'true';

    $responsePayload = [
        'mqtt_username' => $mqtt_creds['mqtt_username'],
        'sync_status' => 'synchronized'
    ];

    // A senha só é incluída se explicitamente solicitado
    if ($revealPassword) {
        $responsePayload['mqtt_password'] = $mqtt_creds['mqtt_password'];
    }

    echo json_encode($responsePayload);

} catch (PDOException $e) {
    http_response_code(500);
    if (APP_ENV === 'production') {
        echo json_encode(['error' => 'Erro ao buscar credenciais MQTT.']);
    } else {
        echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
    }
}
?>
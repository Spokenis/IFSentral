<?php
// cadastrar_device.php (Atualizado)



header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. REQUER AUTENTICAÇÃO
// Agora sabemos quem é o usuário logado
require '../config/db.php';
require '../auth/auth_check.php'; // $username_logado e $_SESSION['user_id'] estão disponíveis
require '../core/MosquittoSync.php';

// Função para gerar credenciais MQTT
function generateMQTTCredentials($conn, $device_id, $api_key) {
    // Username baseado na API key (não expõe ID sequencial)
    $key_hash = substr($api_key, 0, 16); // Primeiros 16 chars da API key
    $username = "mqdev_" . $key_hash;
    $password = bin2hex(random_bytes(12)); // 24 caracteres
    
    // Hash PBKDF2 para Mosquitto
    $salt = random_bytes(12);
    $hash = hash_pbkdf2('sha512', $password, $salt, 101, 64, true);
    $salt_b64 = base64_encode($salt);
    $hash_b64 = base64_encode($hash);
    $password_hash = sprintf('$7$%d$%s$%s', 101, $salt_b64, $hash_b64);
    
    // Insere credenciais no banco
    $stmt = $conn->prepare(
        "INSERT INTO mqtt_credentials (device_id, mqtt_username, mqtt_password_hash, enabled) 
         VALUES (?, ?, ?, 1)"
    );
    $stmt->execute([$device_id, $username, $password_hash]);
    
    return [
        'username' => $username,
        'password' => $password,
        'hash' => $password_hash
    ];
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

// 2. VALIDAÇÃO ATUALIZADA
// Não precisamos mais de 'user_id' no JSON, pois pegamos da SESSÃO.
if (
    !isset($data->project_id) ||
    !isset($data->name) ||
    empty($data->name)
) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Campos obrigatórios: project_id e name.']);
    exit;
}

// 3. PEGA O ID DO USUÁRIO DA SESSÃO
$user_id = $_SESSION['user_id'];
$description = isset($data->description) ? $data->description : null;

// Gera uma chave de API forte e aleatória (64 caracteres)
$api_key = bin2hex(random_bytes(32));

try {
    // BUG FIX: Validar se o usuário pode criar devices neste projeto
    $authSql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ? AND (role_id = 1 OR role_id = 2)";
    $authStmt = $conn->prepare($authSql);
    $authStmt->execute([$data->project_id, $user_id]);
    if ($authStmt->rowCount() == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada. Você não é membro deste projeto ou não tem permissão para criar dispositivos.']);
        exit;
    }
    
    $sql = "INSERT INTO devices (project_id, user_id, name, description, api_key) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    
    // 4. EXECUTA COM O user_id DA SESSÃO
    $stmt->execute([
        $data->project_id,
        $user_id, // Usando o ID da sessão
        $data->name,
        $description,
        $api_key
    ]);
    
    $device_id = $conn->lastInsertId();
    
    // Gera credenciais MQTT automaticamente
    try {
        $mqtt_creds = generateMQTTCredentials($conn, $device_id, $api_key);
        
        // Salva senha em arquivo de backup
        $backup_file = __DIR__ . '/../../mqtt_credentials_auto.txt';
        $backup_entry = sprintf(
            "[%s] Device #%d - %s\nUsername: %s\nPassword: %s\n\n",
            date('Y-m-d H:i:s'),
            $device_id,
            $data->name,
            $mqtt_creds['username'],
            $mqtt_creds['password']
        );
        file_put_contents($backup_file, $backup_entry, FILE_APPEND);
        chmod($backup_file, 0600);
        
        // Sincroniza automaticamente com Mosquitto (sem downtime)
        $sync = new MosquittoSync($conn, true); // true = modo silencioso
        $sync_result = $sync->sync();
        
        $mqtt_info = [
            'username' => $mqtt_creds['username'],
            'password' => $mqtt_creds['password'],
            'sync_status' => $sync_result['success'] ? 'synchronized' : 'pending',
            'note' => $sync_result['success'] ? 'Credenciais MQTT prontas para uso!' : 'Credenciais geradas. Sincronização pendente.'
        ];
    } catch (Exception $e) {
        // Se falhar, não bloqueia criação do device
        $mqtt_info = ['error' => 'Falha ao gerar credenciais MQTT: ' . $e->getMessage()];
    }

    http_response_code(201); // Created
    echo json_encode([
        'message' => 'Dispositivo cadastrado com sucesso!',
        'insertedId' => $device_id,
        'api_key' => $api_key, // Retorna a chave gerada
        'mqtt' => $mqtt_info
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    if ($e->getCode() == '23000') {
         // Erro de chave estrangeira (project_id não existe)
         echo json_encode(['error' => 'Erro: project_id não existe ou é inválido.']);
    } else {
         echo json_encode(['error' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
    }
}
?>
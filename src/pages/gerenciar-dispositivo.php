<?php 
require '../auth/auth_check.php';
require '../config/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id']) || !isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    header('Location: meus-projetos.php');
    exit;
}
$device_id_from_url = intval($_GET['id']);
$project_id_from_url = intval($_GET['project_id']);

// Obter user_id da sessão ou do banco de dados basado no email
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id && isset($_SESSION['email'])) {
    try {
        $sql = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_SESSION['email']]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $user_id = $user_data['id'];
        }
    } catch (Exception $e) {
        header('Location: meus-projetos.php');
        exit;
    }
}

if (!$user_id) {
    header('Location: meus-projetos.php');
    exit;
}

// Validar se o usuário tem permissão para acessar este projeto
try {
    $sql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id_from_url, $user_id]);
    if ($stmt->rowCount() == 0) {
        header('Location: meus-projetos.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: meus-projetos.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gerenciar Dispositivo | IFSentral</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">
  
  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
      --ifsc-light: #2A9B4A;
    }
    
    .wrapper { display: flex; flex-direction: column; min-height: 100vh; }
    .content-wrapper { flex: 1; }
    #status-msg-post, #status-msg-get { margin-top: 15px; }
    .payload-item {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        margin-bottom: 10px;
        padding: 10px;
    }
    .payload-item pre {
        background-color: #fff;
        border: 1px solid #ccc;
        padding: 5px;
        max-height: 150px;
        overflow-y: auto;
    }
    .api-info-box {
        font-family: monospace;
        font-size: 1.1em;
        font-weight: 600;
        color: #000;
        background-color: #f4f4f4;
        padding: 10px;
        border-radius: 4px;
        border: 1px solid #ddd;
    }
    .api-info-box kbd {
        font-size: 1em;
        font-weight: 700;
        background-color: #fff;
        padding: 2px 5px;
        border-radius: 3px;
        border: 1px solid #ccc;
    }
    /* Estilo para o formulário de filtro */
    #form-filtros label {
        font-weight: 500 !important;
        font-size: 0.9rem;
    }
    
    /* IFSC Theme Colors */
    .btn-primary, .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
      background-color: var(--ifsc-primary) !important;
      border-color: var(--ifsc-primary) !important;
    }
    .btn-primary:hover {
      background-color: var(--ifsc-secondary) !important;
    }
    
    .btn-info, .btn-info:hover, .btn-info:focus, .btn-info:active {
      background-color: var(--ifsc-light) !important;
      border-color: var(--ifsc-light) !important;
    }
    .btn-info:hover {
      background-color: var(--ifsc-primary) !important;
    }
    
    .card-primary .card-header {
      background-color: var(--ifsc-primary) !important;
    }
    
    .card-primary {
      border-top-color: var(--ifsc-primary) !important;
    }
    
    .navbar-light .navbar-brand {
      color: var(--ifsc-primary) !important;
    }
  </style>
</head>
<body class="hold-transition layout-top-nav">
  
  <?php
      // Página HTML - sem headers de API JSON
  ?>
<div class="wrapper">

  <?php require_once __DIR__ . '/../includes/header.php'; ?>
  
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 id="device-title">Carregando Dispositivo...</h1>
            <p class="text-muted">Retornando para <a href="gerenciar-projeto.php?id=<?php echo $project_id_from_url; ?>">Gerenciar Projeto</a></p>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="row">
          
          <div class="col-md-5">
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title">Enviar Payload Manualmente</h3>
              </div>
              <form id="form-enviar-manual">
                <div class="card-body">
                  <p>Envie um payload JSON para este dispositivo. Isso é útil para testes.</p>
                  <div class="form-group">
                    <label for="manual-payload">Payload (JSON Válido):</label>
                    <textarea id="manual-payload" class="form-control" rows="5" required>{"temperatura": 25.0, "status": "teste"}</textarea>
                  </div>
                  <div id="status-msg-post"></div>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary" id="enviar-button">Enviar Payload</button>
                </div>
              </form>
            </div>

            <div class="card card-secondary">
              <div class="card-header">
                <h3 class="card-title">Informações de Acesso (API & MQTT)</h3>
              </div>
              <div class="card-body">
                <p>Use estes dados na sua documentação ou dispositivo (ESP, TTN, etc).</p>
                
                <div class="mb-3">
                  <h5>📌 API REST</h5>
                  <div class="api-info-box">
                    <strong>ID do Dispositivo: </strong> <kbd id="api-info-id">Carregando...</kbd><br>
                    <strong class="mt-2 d-block">Chave de API (X-Api-Key): </strong> <kbd id="api-info-key">Carregando...</kbd>
                  </div>
                </div>
                
                <div class="mb-3">
                  <h5>🔌 MQTT</h5>
                  <div class="api-info-box">
                    <strong>Username: </strong> <kbd id="mqtt-username">Carregando...</kbd><br>
                    <strong class="mt-2 d-block">Password: </strong>
                    <div class="mt-2">
                      <kbd id="mqtt-password" style="word-break: break-all;">Carregando...</kbd>
                      <button type="button" class="btn btn-sm btn-outline-secondary ml-2" id="btn-toggle-mqtt-pwd" title="Mostrar/Ocultar">
                        <i class="fas fa-eye"></i>
                      </button>
                      <button type="button" class="btn btn-sm btn-outline-secondary ml-1" id="btn-copy-mqtt-pwd" title="Copiar para área de transferência">
                        <i class="fas fa-copy"></i>
                      </button>
                    </div>
                  </div>
                  <small class="text-muted d-block mt-2">
                    💡 <strong>Host:</strong> localhost (ou seu IP do servidor)<br>
                    💡 <strong>Porta:</strong> 1883 (MQTT padrão)<br>
                    💡 <strong>Protocolo:</strong> MQTT v3.1.1
                  </small>
                </div>
              </div>
            </div>
          </div>

          <div class="col-md-7">
            <div class="card card-info">
              <div class="card-header">
                <h3 class="card-title">Últimos Payloads Recebidos</h3>
              </div>
              <div class="card-body">
                
                <form id="form-filtros" class="form-inline mb-3 bg-light p-2 border rounded">
                  <div class="form-group mr-2">
                    <label for="filter-limit" class="mr-1">Ver:</label>
                    <select id="filter-limit" class="form-control form-control-sm">
                      <option value="10">10 últimos</option>
                      <option value="25">25 últimos</option>
                      <option value="50">50 últimos</option>
                      <option value="100">100 últimos</option>
                    </select>
                  </div>
                  <div class="form-group mr-2">
                    <label for="filter-start" class="mr-1">De:</label>
                    <input type="date" id="filter-start" class="form-control form-control-sm">
                  </div>
                  <div class="form-group mr-2">
                    <label for="filter-end" class="mr-1">Até:</label>
                    <input type="date" id="filter-end" class="form-control form-control-sm">
                  </div>
                  <button type="submit" class="btn btn-sm btn-info">Filtrar</button>
                </form>
                <div id="status-msg-get">Carregando...</div>
                <div id="payloads-container" style="max-height: 400px; overflow-y: auto;">
                  </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </section>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>

</div>

<script>
    const PROJECT_ID = <?php echo $project_id_from_url; ?>;
    const DEVICE_ID = <?php echo $device_id_from_url; ?>;
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>
<script src="../assets/js/fetch-helpers.js"></script>

<script>
    // --- Elementos DOM (Principais) ---
    const deviceTitleEl = document.getElementById('device-title');
    const formEnviar = document.getElementById('form-enviar-manual');
    const txtPayload = document.getElementById('manual-payload');
    const statusMsgPost = document.getElementById('status-msg-post');
    const enviarButton = document.getElementById('enviar-button');
    const statusMsgGet = document.getElementById('status-msg-get');
    const payloadsContainer = document.getElementById('payloads-container');
    const apiInfoId = document.getElementById('api-info-id');
    const apiInfoKey = document.getElementById('api-info-key');
    const mqttUsername = document.getElementById('mqtt-username');
    const mqttPassword = document.getElementById('mqtt-password');
    const btnToggleMqttPwd = document.getElementById('btn-toggle-mqtt-pwd');
    const btnCopyMqttPwd = document.getElementById('btn-copy-mqtt-pwd');
    
    // --- Elementos DOM (Filtros) ---
    const formFiltros = document.getElementById('form-filtros');
    const filterLimit = document.getElementById('filter-limit');
    const filterStart = document.getElementById('filter-start');
    const filterEnd = document.getElementById('filter-end');

    // --- APIs ---
    const API_OBTER_DISPOSITIVO = 'obter_info_dispositivo.php';
    const API_MQTT_CREDENTIALS = 'get_mqtt_credentials.php';
    const API_ENVIAR = '../api/enviar_payload.php';
    const API_BUSCAR = '../api/buscar_payloads.php';

    let DEVICE_API_KEY = null;

    // Função 1: Carrega os detalhes do dispositivo
    async function carregarDetalhesDispositivo() {
        try {
            const response = await fetch(`${API_OBTER_DISPOSITIVO}?device_id=${DEVICE_ID}`, { 
                credentials: 'include' 
            });
            const device = await safeJson(response);
            
            if (!device || !device.id) {
                deviceTitleEl.textContent = "Dispositivo não encontrado";
                throw new Error('Dispositivo não encontrado');
            }
            
            deviceTitleEl.textContent = `Gerenciando: ${device.name}`;
            DEVICE_API_KEY = device.api_key;
            
            apiInfoId.textContent = device.id;
            apiInfoKey.textContent = device.api_key;
            
            await carregarCredenciaisMQTT();
            await carregarPayloads();

        } catch (error) {
            statusMsgGet.innerHTML = `<span style="color: red;">${error.message}</span>`;
            enviarButton.disabled = true; 
        }
    }

    // Função 1.5: Carrega credenciais MQTT (Apenas dados não-sensíveis no load)
    async function carregarCredenciaisMQTT() {
        try {
            const response = await fetch(API_MQTT_CREDENTIALS, {
                credentials: 'include',
                headers: { 'X-Api-Key': DEVICE_API_KEY }
            });
            const mqtt_creds = await safeJson(response);
            
            if (!mqtt_creds || !mqtt_creds.mqtt_username) {
                mqttUsername.textContent = 'Não configurado';
                mqttPassword.textContent = 'N/A';
                btnToggleMqttPwd.disabled = true;
                btnCopyMqttPwd.disabled = true;
                return;
            }
            
            mqttUsername.textContent = mqtt_creds.mqtt_username;
            mqttPassword.textContent = '••••••••••••••••';
            mqttPassword.dataset.visible = 'false';
            
        } catch (error) {
            console.warn('Não foi possível carregar credenciais MQTT:', error.message);
            mqttUsername.textContent = 'Não disponível';
            mqttPassword.textContent = 'N/A';
        }
    }

    // Função auxiliar para buscar a senha sob demanda
    async function fetchMqttPassword() {
        const response = await fetch(`${API_MQTT_CREDENTIALS}?reveal=true`, {
            credentials: 'include',
            headers: { 'X-Api-Key': DEVICE_API_KEY }
        });
        const data = await safeJson(response);
        return data.mqtt_password ?? '';
    }

    // Event Listener: Mostrar/Ocultar a senha temporariamente
    if (btnToggleMqttPwd) {
        btnToggleMqttPwd.addEventListener('click', async () => {
            const isVisible = mqttPassword.dataset.visible === 'true';
            
            if (isVisible) {
                mqttPassword.textContent = '••••••••••••••••';
                mqttPassword.dataset.visible = 'false';
                btnToggleMqttPwd.innerHTML = '<i class="fas fa-eye"></i>';
            } else {
                btnToggleMqttPwd.disabled = true;
                try {
                    const password = await fetchMqttPassword();
                    if (password) {
                        mqttPassword.textContent = password;
                        mqttPassword.dataset.visible = 'true';
                        btnToggleMqttPwd.innerHTML = '<i class="fas fa-eye-slash"></i>';
                        
                        setTimeout(() => {
                            if (mqttPassword.dataset.visible === 'true') {
                                mqttPassword.textContent = '••••••••••••••••';
                                mqttPassword.dataset.visible = 'false';
                                btnToggleMqttPwd.innerHTML = '<i class="fas fa-eye"></i>';
                            }
                        }, 15000);
                    }
                } catch (e) {
                    console.error('Erro ao recuperar credencial:', e);
                } finally {
                    btnToggleMqttPwd.disabled = false;
                }
            }
        });
    }

    // Event Listener: Copiar senha MQTT
    if (btnCopyMqttPwd) {
        btnCopyMqttPwd.addEventListener('click', async () => {
            btnCopyMqttPwd.disabled = true;
            try {
                const password = await fetchMqttPassword();
                if (!password) return;
                
                await navigator.clipboard.writeText(password);
                const originalHTML = btnCopyMqttPwd.innerHTML;
                btnCopyMqttPwd.innerHTML = '<i class="fas fa-check"></i> Copiado!';
                setTimeout(() => {
                    btnCopyMqttPwd.innerHTML = originalHTML;
                    btnCopyMqttPwd.disabled = false;
                }, 2000);
            } catch (err) {
                alert('Erro ao copiar: ' + err);
                btnCopyMqttPwd.disabled = false;
            }
        });
    }

    // Função 2: Carrega a lista de payloads recebidos
    async function carregarPayloads() {
        if (!DEVICE_API_KEY) return; 

        statusMsgGet.innerHTML = 'Buscando payloads...';
        payloadsContainer.innerHTML = '';
        
        const limit = filterLimit.value;
        const startDate = filterStart.value;
        const endDate = filterEnd.value;

        let url = `${API_BUSCAR}?device_id=${DEVICE_ID}&limit=${limit}`;
        if (startDate) url += `&startDate=${startDate}`;
        if (endDate) url += `&endDate=${endDate}`;
        
        try {
            const response = await fetch(url, {
              method: 'GET',
              headers: { 'X-Api-Key': DEVICE_API_KEY }
            });
            const payloads = await safeJson(response);
            
            if (payloads.length === 0) {
                statusMsgGet.innerHTML = 'Nenhum payload encontrado para estes filtros.';
                return;
            }
            
            statusMsgGet.style.display = 'none'; 
            
            payloads.forEach(item => {
                const dataFormatada = new Date(item.created_at).toLocaleString('pt-BR');
                const payloadFormatado = JSON.stringify(item.payload, null, 2); 
                
                const itemDiv = document.createElement('div');
                itemDiv.className = 'payload-item';
                itemDiv.innerHTML = `
                    <small>Recebido em: ${dataFormatada}</small>
                    <pre>${payloadFormatado}</pre>
                `;
                payloadsContainer.appendChild(itemDiv);
            });

        } catch (error) {
            statusMsgGet.innerHTML = `<span style="color: red;">${error.message}</span>`;
        }
    }

    // Função 3: Lida com o envio do formulário manual
    formEnviar.addEventListener('submit', async function(event) {
        event.preventDefault();
        statusMsgPost.innerHTML = 'Enviando...';
        enviarButton.disabled = true;

        let payloadObj;
        try {
            payloadObj = JSON.parse(txtPayload.value);
        } catch (e) {
            statusMsgPost.innerHTML = `<span style="color: red;">Erro: O texto não é um JSON válido.</span>`;
            enviarButton.disabled = false;
            return;
        }

        const data = {
            device_id: DEVICE_ID,
            payload: payloadObj
        };

        try {
            const response = await fetch(API_ENVIAR, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Api-Key': DEVICE_API_KEY
              },
              body: JSON.stringify(data)
            });
            const resultado = await safeJson(response);

            statusMsgPost.innerHTML = `<span style="color: green;">${resultado.message}</span>`;
            enviarButton.disabled = false;
            
            await carregarPayloads(); 

        } catch (error) {
            statusMsgPost.innerHTML = `<span style="color: red;">${error.message}</span>`;
            enviarButton.disabled = false;
        }
    });

    // Event Listener para o formulário de filtros
    formFiltros.addEventListener('submit', async (e) => {
        e.preventDefault(); 
        await carregarPayloads(); 
    });

    // Inicializador
    document.addEventListener('DOMContentLoaded', carregarDetalhesDispositivo);
</script>
</body>
</html>
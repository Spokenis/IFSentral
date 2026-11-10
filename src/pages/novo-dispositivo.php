<?php 
require '../auth/auth_check.php';
require '../config/db.php';

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    header('Location: /meus-projetos');
    exit;
}
$project_id_from_url = intval($_GET['project_id']);

// Obter user_id da sessão ou do banco de dados baseado no e-mail
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
        header('Location: /meus-projetos');
        exit;
    }
}

if (!$user_id) {
    header('Location: /meus-projetos');
    exit;
}

// Validar se o usuário tem permissão para acessar este projeto
try {
    $sql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id_from_url, $user_id]);
    if ($stmt->rowCount() == 0) {
        header('Location: /meus-projetos');
        exit;
    }
} catch (Exception $e) {
    header('Location: /meus-projetos');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Novo Dispositivo | IFSCentral</title>

  <link rel="stylesheet" href="/assets/vendor/fonts/source-sans-pro.css">
  <link rel="stylesheet" href="/assets/vendor/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/assets/vendor/admin-lte/3.2.0/css/adminlte.min.css">
  
  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
      --ifsc-light: #2A9B4A;
    }
    
    .wrapper { display: flex; flex-direction: column; min-height: 100vh; }
    .content-wrapper { flex: 1; }
    #status-msg { margin-top: 15px; }
    /* Painel exibido após o cadastro: um campo por linha, valor em fonte
       monoespaçada que quebra linha (a chave de API tem 64 caracteres e
       estourava a largura do card) e botão de copiar ao lado */
    .credenciais h5 { font-size: 1rem; font-weight: 700; margin: 18px 0 8px; color: var(--ifsc-secondary); }
    .credenciais h5:first-of-type { margin-top: 0; }
    .credencial { margin-bottom: 10px; }
    .credencial label { display: block; font-size: .85rem; font-weight: 600; color: #555; margin-bottom: 3px; }
    .credencial .valor { display: flex; align-items: stretch; }
    .credencial code {
        flex: 1; min-width: 0;
        font-size: .95rem; color: #212529;
        background: #f8f9fa; border: 1px solid #dee2e6; border-right: 0;
        border-radius: 4px 0 0 4px; padding: 6px 10px;
        word-break: break-all;
    }
    .credencial .btn { border-radius: 0 4px 4px 0; }
    
    /* IFSC Theme Colors */
    .btn-primary, .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
      background-color: var(--ifsc-primary) !important;
      border-color: var(--ifsc-primary) !important;
    }
    .btn-primary:hover {
      background-color: var(--ifsc-secondary) !important;
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
<div class="wrapper">

  <?php require_once __DIR__ . '/../includes/header.php'; ?>
  
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>Adicionar Novo Dispositivo</h1>
            <p class="text-muted">Retornando para <a href="/projeto?id=<?php echo $project_id_from_url; ?>">Gerenciar Projeto</a></p>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="row">
          <div class="col-md-8 offset-md-2">
            
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title">Detalhes do Dispositivo</h3>
              </div>
              <form id="form-cadastrar">
                <div class="card-body">
                  <div class="form-group">
                    <label for="device-name">Nome do Dispositivo:</label>
                    <input type="text" id="device-name" maxlength="100" class="form-control" placeholder="Ex: Raspberry Pi Sala 204" required>
                  </div>
                  <div class="form-group">
                    <label for="device-desc">Descrição:</label>
                    <textarea id="device-desc" maxlength="200" class="form-control" rows="3" placeholder="Ex: Sensor de temperatura e umidade DHT22"></textarea>
                  </div>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary" id="cadastrar-button">Cadastrar Dispositivo</button>
                  <a href="/projeto?id=<?php echo $project_id_from_url; ?>" class="btn btn-secondary">Cancelar</a>
                </div>
              </form>
              
              <div id="status-msg" style="padding: 0 20px 20px 20px;"></div>

              <div id="painel-credenciais" class="card-body credenciais" style="display: none;">
                <div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i><span id="cred-mensagem"></span></div>
                <p class="text-muted small mb-3">Copie estes dados para o firmware do dispositivo. Eles continuam disponíveis na página do dispositivo (botão <em>Gerenciar</em>).</p>

                <h5><i class="fas fa-globe mr-1"></i> HTTP (API REST)</h5>
                <div class="credencial"><label>ID do dispositivo (<code>device_id</code>)</label><div class="valor"><code id="cred-device-id"></code><button type="button" class="btn btn-outline-secondary btn-sm btn-copiar" data-copiar="cred-device-id" title="Copiar"><i class="fas fa-copy"></i></button></div></div>
                <div class="credencial"><label>Chave de API (header <code>X-Api-Key</code>)</label><div class="valor"><code id="cred-api-key"></code><button type="button" class="btn btn-outline-secondary btn-sm btn-copiar" data-copiar="cred-api-key" title="Copiar"><i class="fas fa-copy"></i></button></div></div>
                <div class="credencial"><label>Endpoint</label><div class="valor"><code id="cred-endpoint"></code><button type="button" class="btn btn-outline-secondary btn-sm btn-copiar" data-copiar="cred-endpoint" title="Copiar"><i class="fas fa-copy"></i></button></div></div>

                <div id="bloco-mqtt">
                  <h5><i class="fas fa-broadcast-tower mr-1"></i> MQTT</h5>
                  <div class="credencial"><label>Tópico de publicação</label><div class="valor"><code id="cred-topico"></code><button type="button" class="btn btn-outline-secondary btn-sm btn-copiar" data-copiar="cred-topico" title="Copiar"><i class="fas fa-copy"></i></button></div></div>
                  <div class="credencial"><label>Usuário</label><div class="valor"><code id="cred-mqtt-user"></code><button type="button" class="btn btn-outline-secondary btn-sm btn-copiar" data-copiar="cred-mqtt-user" title="Copiar"><i class="fas fa-copy"></i></button></div></div>
                  <div class="credencial"><label>Senha</label><div class="valor"><code id="cred-mqtt-pass"></code><button type="button" class="btn btn-outline-secondary btn-sm btn-copiar" data-copiar="cred-mqtt-pass" title="Copiar"><i class="fas fa-copy"></i></button></div></div>
                  <div class="credencial"><label>Host e porta</label><div class="valor"><code id="cred-mqtt-host"></code><button type="button" class="btn btn-outline-secondary btn-sm btn-copiar" data-copiar="cred-mqtt-host" title="Copiar"><i class="fas fa-copy"></i></button></div></div>
                  <p class="text-muted small mb-0" id="cred-mqtt-nota"></p>
                </div>

                <div class="mt-4">
                  <a id="link-dispositivo" href="#" class="btn btn-primary"><i class="fas fa-cog mr-1"></i>Abrir dispositivo</a>
                  <a href="/projeto?id=<?php echo $project_id_from_url; ?>" class="btn btn-secondary">Voltar ao projeto</a>
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
</script>

<script src="/assets/vendor/jquery/3.7.1/jquery.min.js"></script>
<script src="/assets/vendor/bootstrap/4.6.2/bootstrap.bundle.min.js"></script>
<script src="/assets/js/input-limits.js"></script>
<script src="/assets/vendor/admin-lte/3.2.0/js/adminlte.min.js"></script>
<script src="/assets/js/fetch-helpers.js"></script>

<script>
    const formCadastrar = document.getElementById('form-cadastrar');
    const statusMsg = document.getElementById('status-msg');
    const cadastrarButton = document.getElementById('cadastrar-button');
    const API_URL = '/api/cadastrar-device'; 

    function preencher(id, texto) {
        document.getElementById(id).textContent = texto;
    }

    // Mostra as credenciais no painel (sem redirecionar sozinho: antes a
    // página voltava ao projeto depois de 10 s, às vezes antes de copiar)
    function mostrarCredenciais(r) {
        statusMsg.innerHTML = '';
        formCadastrar.style.display = 'none';
        preencher('cred-mensagem', r.message);
        preencher('cred-device-id', r.insertedId);
        preencher('cred-api-key', r.api_key);
        preencher('cred-endpoint', `POST ${window.location.origin}/api/enviar-payload`);

        if (r.mqtt && r.mqtt.username) {
            preencher('cred-topico', `mqtt/projects/${PROJECT_ID}/devices/${r.insertedId}`);
            preencher('cred-mqtt-user', r.mqtt.username);
            preencher('cred-mqtt-pass', r.mqtt.password);
            preencher('cred-mqtt-host', `${window.location.hostname}:1883 (ou 8883 com TLS)`);
            preencher('cred-mqtt-nota', r.mqtt.note || '');
        } else {
            document.getElementById('bloco-mqtt').innerHTML =
                '<h5><i class="fas fa-broadcast-tower mr-1"></i> MQTT</h5><p class="text-muted small">' +
                escapeHtmlSimples((r.mqtt && r.mqtt.error) || 'Credenciais MQTT indisponíveis.') + '</p>';
        }
        document.getElementById('link-dispositivo').href = `/dispositivo?id=${r.insertedId}&project_id=${PROJECT_ID}`;
        document.getElementById('painel-credenciais').style.display = '';
    }

    function escapeHtmlSimples(t) {
        const d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    // navigator.clipboard só existe em HTTPS/localhost; em http://IP usa o fallback
    async function copiarTexto(texto) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(texto);
            return;
        }
        const ta = document.createElement('textarea');
        ta.value = texto;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (!ok) throw new Error('o navegador bloqueou a cópia');
    }

    document.querySelectorAll('.btn-copiar').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                await copiarTexto(document.getElementById(btn.dataset.copiar).textContent.trim());
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => { btn.innerHTML = original; }, 1500);
            } catch (err) {
                ifsAlerta('Erro ao copiar: ' + err.message, { tipo: 'erro' });
            }
        });
    });

    formCadastrar.addEventListener('submit', async function(event) {
        event.preventDefault();
        statusMsg.innerHTML = 'Salvando...';
        statusMsg.style.color = 'black';
        cadastrarButton.disabled = true;
        
        const data = {
            name: document.getElementById('device-name').value,
            description: document.getElementById('device-desc').value,
            project_id: PROJECT_ID
        };

        try {
            const response = await fetch(API_URL, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(data),
              credentials: 'include'
            });
            const resultado = await safeJson(response);

            mostrarCredenciais(resultado);
        } catch (error) {
            statusMsg.innerHTML = `<span style="color: red;">${error.message}</span>`;
            cadastrarButton.disabled = false;
        }
    });
</script>
<script src="/assets/js/profile-picture-helper.js"></script>
</body>
</html>
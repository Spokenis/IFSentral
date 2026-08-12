<?php 
require '../auth/auth_check.php';
require '../config/db.php';

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    header('Location: meus-projetos.php');
    exit;
}
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
  <title>Novo Dispositivo | IFSentral</title>

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
    #status-msg { margin-top: 15px; }
    .api-key-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; }
    .api-key-success strong { font-family: monospace; font-size: 1.1em; }
    /* Estilo para destacar o ID */
    .api-key-success span { 
        font-family: monospace; 
        font-size: 1.1em; 
        font-weight: 600;
        color: #000;
        background-color: #fff;
        padding: 2px 5px;
        border-radius: 3px;
    }
    
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
            <p class="text-muted">Retornando para <a href="gerenciar-projeto.php?id=<?php echo $project_id_from_url; ?>">Gerenciar Projeto</a></p>
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
                    <input type="text" id="device-name" class="form-control" placeholder="Ex: Raspberry Pi Sala 204" required>
                  </div>
                  <div class="form-group">
                    <label for="device-desc">Descrição:</label>
                    <textarea id="device-desc" class="form-control" rows="3" placeholder="Ex: Sensor de temperatura e umidade DHT22"></textarea>
                  </div>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary" id="cadastrar-button">Cadastrar Dispositivo</button>
                  <a href="gerenciar-projeto.php?id=<?php echo $project_id_from_url; ?>" class="btn btn-secondary">Cancelar</a>
                </div>
              </form>
              
              <div id="status-msg" style="padding: 0 20px 20px 20px;"></div>

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

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>
<script src="../assets/js/fetch-helpers.js"></script>

<script>
    const formCadastrar = document.getElementById('form-cadastrar');
    const statusMsg = document.getElementById('status-msg');
    const cadastrarButton = document.getElementById('cadastrar-button');
    const API_URL = 'cadastrar_device.php'; 

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

            // *** ALTERAÇÃO AQUI: Mostra o ID do Dispositivo ***
            statusMsg.innerHTML = `
                <div class="api-key-success">
                    <strong>${resultado.message}</strong><br>
                    Guarde estas informações com segurança. Elas são necessárias para a API.
                    <br><br>
                    <strong>ID do Dispositivo: </strong> <span>${resultado.insertedId}</span><br>
                    <strong>Chave de API (X-Api-Key): </strong> <strong>${resultado.api_key}</strong>
                </div>
            `;
            
            formCadastrar.reset();
            
            // Aumentei o tempo para 10 segundos para dar tempo de copiar
            setTimeout(() => {
                window.location.href = `gerenciar-projeto.php?id=${PROJECT_ID}`;
            }, 10000); // 10 segundos

        } catch (error) {
            statusMsg.innerHTML = `<span style="color: red;">${error.message}</span>`;
            cadastrarButton.disabled = false;
        }
    });
</script>
</body>
</html>
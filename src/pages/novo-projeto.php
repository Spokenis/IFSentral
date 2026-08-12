<?php 
// 1. VERIFICA SE O USUÁRIO ESTÁ LOGADO
require '../auth/auth_check.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Criar Novo Projeto | IFSentral</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">
  
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet" />
  
  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
      --ifsc-light: #2A9B4A;
    }
    
    .wrapper { display: flex; flex-direction: column; min-height: 100vh; }
    .content-wrapper { flex: 1; }
    #status-msg { margin-top: 15px; }
    /* Garante que o select2 ocupe a largura correta */
    .select2-container--bootstrap4 .select2-selection--multiple {
        min-height: calc(2.25rem + 2px);
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
            <h1>Criar Novo Projeto</h1>
            <p class="text-muted">Retornando de <a href="meus-projetos.php">Meus Projetos</a></p>
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
                <h3 class="card-title">Detalhes do Novo Projeto</h3>
              </div>
              <form id="form-criar">
                <div class="card-body">
                  
                  <div class="form-group">
                    <label for="proj-name">Nome do Projeto:</label>
                    <input type="text" id="proj-name" class="form-control" required>
                  </div>
                  
                  <div class="form-group">
                    <label for="proj-desc">Descrição:</label>
                    <textarea id="proj-desc" class="form-control" rows="3"></textarea>
                  </div>
                  
                  <div class="form-group">
                    <label for="proj-tags">Tags (digite para criar ou selecione):</label>
                    <select id="proj-tags" class="form-control" multiple="multiple"></select>
                  </div>
                  
                  <div class="form-group">
                    <label for="proj-max-users">Máximo de Usuários (deixe 0 para ilimitado):</label>
                    <input type="number" id="proj-max-users" class="form-control" min="0" value="0">
                  </div>
                  
                  <div class="form-group">
                    <div class="form-check">
                      <input type="checkbox" id="proj-public" class="form-check-input">
                      <label for="proj-public" class="form-check-label">Projeto Público (visível na página "Explorar")</label>
                    </div>
                  </div>
                  
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary" id="criar-button">Criar Projeto</button>
                  <div id="status-msg" style="display: inline-block; margin-left: 15px;"></div>
                </div>
              </form>
            </div>

          </div>
        </div>
      </div>
    </section>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js"></script>
<script src="../assets/js/fetch-helpers.js"></script>

<script>
    // --- Declarações Globais (feitas apenas UMA VEZ) ---
    const formCriar = document.getElementById('form-criar');
    const statusMsg = document.getElementById('status-msg');
    const criarButton = document.getElementById('criar-button');
    const API_URL = 'criar_projeto.php'; 
    const API_TAGS_URL = '../api/listar_tags.php';
    // Não precisamos de uma variável global para o seletor com jQuery
    
    // --- Função para carregar o Select2 ---
    async function inicializarSeletorDeTags() {
        let tagsExistentesFormatadas = [];
        try {
            const response = await fetch(API_TAGS_URL, { credentials: 'include' });
            const tagsApi = await safeJson(response).catch(() => []);
            
            // O Select2 prefere {id: 1, text: 'IoT'}, então vamos formatar
            tagsExistentesFormatadas = tagsApi.map(tag => {
                return { id: tag.value, text: tag.text };
            });
            
        } catch (error) {
            console.error(error);
            // Continua mesmo se as tags não carregarem
        }
        
        // Inicializa o seletor
        $('#proj-tags').select2({
            theme: 'bootstrap4', // Usa o tema do AdminLTE
            placeholder: 'Ex: IoT, Automação, Sensor...',
            tags: true, // Permite criar novas tags
            data: tagsExistentesFormatadas // Popula com tags do banco
        });
    }
    
    // --- Função de Submit do Formulário ---
    formCriar.addEventListener('submit', async function(event) {
        event.preventDefault();
        statusMsg.innerHTML = 'Salvando...';
        statusMsg.style.color = 'black';
        criarButton.disabled = true;

        const maxUsersVal = parseInt(document.getElementById('proj-max-users').value);
        
        const data = {
            name: document.getElementById('proj-name').value,
            description: document.getElementById('proj-desc').value,
            public: document.getElementById('proj-public').checked ? 1 : 0, // Converter boolean para inteiro
            maxUsers: maxUsersVal > 0 ? maxUsersVal : null,
            tags: $('#proj-tags').val() // Pega os valores do Select2
        };

        try {
            const response = await fetch(API_URL, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(data),
              credentials: 'include' 
            });
            const resultado = await safeJson(response);

            statusMsg.innerHTML = `<span style="color: green;">${resultado.message} Redirecionando...</span>`;
            formCriar.reset();
            $('#proj-tags').val(null).trigger('change'); // Limpa o Select2
            
            setTimeout(() => {
                window.location.href = `gerenciar-projeto.php?id=${resultado.insertedId}`;
            }, 2000);

        } catch (error) {
            statusMsg.innerHTML = `<span style="color: red;">${error.message}</span>`;
            criarButton.disabled = false;
        }
    });

    // --- Inicializador ---
    // Roda a inicialização do seletor de tags quando o DOM estiver pronto
    document.addEventListener('DOMContentLoaded', inicializarSeletorDeTags);

</script>
</body>
</html>
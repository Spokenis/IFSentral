<?php 
require '../auth/auth_check.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Explorar Projetos | IFSentral</title>

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
    .card .badge { color: white !important; }
    .badge-warning { color: #212529 !important; }
    #status-msg { margin-top: 15px; }
    
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

  <nav class="main-header navbar navbar-expand-md navbar-light navbar-white">
    <div class="container">
      <a href="index.html" class="navbar-brand"> <span class="brand-text font-weight-bold">IFSentral</span>
      </a>

      <button class="navbar-toggler order-1" type="button" data-toggle="collapse" data-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse order-3" id="navbarCollapse">
        <ul class="navbar-nav">
          <li class="nav-item">
            <a href="meus-projetos.php" class="nav-link">Meus Projetos</a>
          </li>
          <li class="nav-item">
            <a href="explorar_projetos.php" class="nav-link active">Explorar Projetos</a>
          </li>
          <li class="nav-item">
            <a href="documentacao.php" class="nav-link">Documentação da API</a>
          </li>
        </ul>
      </div>

      <ul class="order-1 order-md-3 navbar-nav navbar-no-expand ml-auto">
        <li class="nav-item dropdown">
          <a class="nav-link navbar-user-avatar" data-toggle="dropdown" href="#">
            <i class="fas fa-user-circle"></i>
            <span>
                <?php 
                echo htmlspecialchars($username_logado); 
                ?>
            </span>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <a href="perfil.php" class="dropdown-item"><i class="fas fa-user mr-2"></i> Meu Perfil</a>
            <a href="meus-dispositivos.php" class="dropdown-item"><i class="fas fa-microchip mr-2"></i> Meus Sensores</a>
            <a href="configuracoes.php" class="dropdown-item"><i class="fas fa-cog mr-2"></i> Configurações</a>
            <div class="dropdown-divider"></div>
            <a href="logout_api.php" class="dropdown-item"><i class="fas fa-sign-out-alt mr-2 text-danger"></i> Sair</a>
          </div>
        </li>
      </ul>
    </div>
  </nav>
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>Explorar Projetos</h1>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="row mb-4">
            <div class="col-md-12">
                <form action="#">
                    <div class="input-group">
                        <input type="search" class="form-control form-control-lg" placeholder="Buscar por nome, tag ou professor...">
                        <div class="input-group-append">
                            <button type="submit" class="btn btn-lg btn-default"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row" id="project-list-container">
            </div>
        
        <div class="row">
            <div id="status-msg" class="col-12" style="display: none;"></div>
        </div>
        
      </div>
    </section>
  </div>

  <footer class="main-footer text-center">
    <strong>Copyright &copy; 2024-2025 <a href="index.html">IFSentral</a>.</strong> Todos os direitos reservados.
  </footer>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>
<script src="../assets/js/fetch-helpers.js"></script>

<script>
    const API_URL_LISTAR = 'listar_projetos_publicos.php';
    const container = document.getElementById('project-list-container');
    const statusMsg = document.getElementById('status-msg');

    // *** Função de criar card ATUALIZADA com TAGS ***
    function criarCardProjeto(proj) {
        
        const gerente = proj.manager_name;
        const participantes = Number(proj.participant_count || 0);
        const maxUsers = proj.maxUsers;
        const isMember = Number(proj.is_member || 0) === 1;
        const memberBadge = isMember
            ? '<span class="badge badge-success">Você é membro</span>'
            : '<span class="badge badge-secondary">Você não é membro</span>';
        
        // --- LÓGICA DAS TAGS ---
        let tagsHtml = '';
        if (proj.project_tags) { // project_tags será "IoT,Sensor,Casa"
            const tagsArray = proj.project_tags.split(',');
            tagsArray.forEach(tag => {
                // Atribui uma cor aleatória simples
                const cores = ['badge-primary', 'badge-info', 'badge-success', 'badge-warning', 'badge-danger'];
                const cor = cores[Math.floor(Math.random() * cores.length)];
                tagsHtml += `<span class="badge ${cor} mr-1">${tag}</span> `;
            });
        } else {
            tagsHtml = '<span class="badge badge-secondary">Sem tags</span>';
        }
        // --- FIM DA LÓGICA DAS TAGS ---
        
        let maxUsersText = '';
        let isLotado = false;
        
        if (maxUsers) { 
            maxUsersText = `/ ${maxUsers} Participantes`;
            if (participantes >= maxUsers) {
                isLotado = true;
                maxUsersText += " (Lotado)";
            }
        } else {
            maxUsersText = 'Participantes (Ilimitado)';
        }

        const botao = isLotado
            ? '<a href="#" class="btn btn-secondary btn-sm disabled">Solicitar Participação</a>'
            : `<a href="visualizar_projeto.php?id=${proj.id}" class="btn btn-primary btn-sm">Saber Mais</a>`;

        return `
        <div class="col-md-6 col-lg-4 mb-4">
          <div class="card card-primary card-outline h-100">
            <div class="card-body d-flex flex-column">
              <div>
                <h5 class="card-title"><b>${proj.name}</b></h5>
                <p class="card-text mt-3">${proj.description || '<i>Sem descrição</i>'}</p>
              </div>
              <div class="mt-auto pt-3">
                <p class="text-sm text-muted mb-2">
                  <strong>Gerente:</strong> ${gerente}<br>
                  <strong>Status:</strong> ${memberBadge}<br>
                  <strong>Tags:</strong> ${tagsHtml}
                </p>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted ${isLotado ? 'text-danger' : ''}"><i class="fas fa-users mr-1"></i> ${participantes} ${maxUsersText}</div>
                    ${botao}
                </div>
              </div>
            </div>
          </div>
        </div>
        `;
    }

    async function carregarProjetos() {
        container.innerHTML = '';
        statusMsg.innerHTML = 'Carregando projetos...';
        statusMsg.style.display = 'block';

        try {
            const response = await fetch(API_URL_LISTAR, {
                credentials: 'include'
            });
            const projetosPublicos = await safeJson(response);
            if (projetosPublicos.error) throw new Error(projetosPublicos.error);
            
            if (projetosPublicos.length === 0) {
                statusMsg.innerHTML = 'Nenhum projeto público encontrado no momento.';
                return;
            }

            statusMsg.style.display = 'none';

            let htmlCards = '';
            projetosPublicos.forEach(proj => {
                htmlCards += criarCardProjeto(proj);
            });
            container.innerHTML = htmlCards;

        } catch (error) {
            statusMsg.innerHTML = `<span style="color: red;">${error.message}</span>`;
        }
    }
    document.addEventListener('DOMContentLoaded', carregarProjetos);
</script>
<script src="../assets/js/profile-picture-helper.js"></script>
</body>
</html>
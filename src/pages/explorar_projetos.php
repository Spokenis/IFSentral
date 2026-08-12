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

  <?php require_once __DIR__ . '/../includes/header.php'; ?>
  
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
                        <input type="search" class="form-control form-control-lg" placeholder="Buscar por nome, tag ou gerente...">
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

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>

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
        if (typeof proj.project_tags === 'string' && proj.project_tags.trim().length > 0) {
            const tagsArray = proj.project_tags
                .split(',')
                .map(t => (t || '').trim())
                .filter(t => t.length > 0);

            if (tagsArray.length > 0) {
                const cores = ['badge-primary', 'badge-info', 'badge-success', 'badge-warning', 'badge-danger'];
                tagsArray.forEach((tag, index) => {
                    const cor = cores[index % cores.length];
                    tagsHtml += `<span class="badge ${cor} mr-1">${tag}</span> `;
                });
            } else {
                tagsHtml = '<span class="text-muted">(Sem tags)</span>';
            }
        } else {
            tagsHtml = '<span class="text-muted">(Sem tags)</span>';
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
<?php 
require '../auth/auth_check.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meus Projetos | IFSentral</title>

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
            <h1>Meus Projetos</h1>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="card card-primary card-outline">
          <div class="card-header">
            <h3 class="card-title">Projetos que você participa</h3>
            <div class="card-tools">
              <a href="novo-projeto.php" class="btn btn-primary"><i class="fas fa-plus"></i> Criar Novo Projeto</a>
            </div>
          </div>
          <div class="card-body table-responsive p-0">
            <table class="table table-hover text-nowrap">
              <thead>
                <tr>
                  <th>Nome do Projeto</th>
                  <th>Minha Função</th>
                  <th>Participantes</th>
                  <th>Dispositivos</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody id="projetos-lista-tbody">
                </tbody>
            </table>
            <div id="status-msg" style="padding: 15px; display: none;"></div>
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
<script src="../assets/js/fetch-helpers.js"></script>

<script>
    const API_URL_LISTAR = 'listar_projetos.php';
    const tbody = document.getElementById('projetos-lista-tbody');
    const statusMsg = document.getElementById('status-msg');

    async function carregarProjetos() {
        tbody.innerHTML = ''; 
        statusMsg.innerHTML = 'Carregando projetos...';
        statusMsg.style.display = 'block';

        try {
            const response = await fetch(API_URL_LISTAR, {
                credentials: 'include' 
            });

            const projetos = await safeJson(response);

            if (projetos.error) {
              throw new Error(projetos.error);
            }

            if (projetos.length === 0) {
                statusMsg.innerHTML = 'Você ainda não participa de nenhum projeto. Crie um novo!';
                return;
            }

            statusMsg.style.display = 'none'; 

            // *** ALTERAÇÃO PRINCIPAL AQUI ***
            // 6. Loop para criar as linhas da tabela (AGORA 100% DINÂMICO)
            projetos.forEach(proj => {
                const row = tbody.insertRow();
                
                // Formata o nome da função (Gerente = verde, Participante = azul)
                const funcaoBadge = proj.user_role_name === 'Gerente' 
                    ? '<span class="badge bg-success">Gerente</span>' 
                    : '<span class="badge bg-info">Participante</span>';
                
                // Ações: Se não for Gerente, não pode ver o botão "Gerenciar"
                // (mas ainda pode ver os dados)
                const acoes = `
                    ${proj.user_role_name === 'Gerente' 
                        ? `<a href="gerenciar-projeto.php?id=${proj.id}" class="btn btn-primary btn-sm">Gerenciar</a>` 
                        : ''}
                    <a href="gerenciar-projeto.php?id=${proj.id}" class="btn btn-secondary btn-sm">Ver Dados</a>
                `;
                
                // Preenche a linha com os dados REAIS da API
                row.innerHTML = `
                    <td>${proj.name}</td>
                    <td>${funcaoBadge}</td>
                    <td>${proj.participant_count}</td>
                    <td>${proj.device_count}</td>
                    <td>${acoes}</td>
                `;
            });

        } catch (error) {
            statusMsg.innerHTML = `<span style="color: red;">${error.message}</span>`;
        }
    }

    document.addEventListener('DOMContentLoaded', carregarProjetos);
</script>
<script src="../assets/js/profile-picture-helper.js"></script>
</body>
</html>
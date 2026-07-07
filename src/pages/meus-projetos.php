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

  <nav class="main-header navbar navbar-expand-md navbar-light navbar-white">
    <div class="container">
      <a href="index.html" class="navbar-brand">
        <span class="brand-text font-weight-bold">IFSentral</span>
      </a>

      <button class="navbar-toggler order-1" type="button" data-toggle="collapse" data-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse order-3" id="navbarCollapse">
        <ul class="navbar-nav">
          <li class="nav-item">
            <a href="meus-projetos.php" class="nav-link active">Meus Projetos</a>
          </li>
          <li class="nav-item">
            <a href="explorar_projetos.php" class="nav-link">Explorar Projetos</a>
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

  <footer class="main-footer text-center">
    <strong>Copyright &copy; 2024-2025 <a href="index.html">IFSentral</a>.</strong> Todos os direitos reservados.
  </footer>

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
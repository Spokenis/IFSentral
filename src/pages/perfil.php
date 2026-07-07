<?php 
require '../auth/auth_check.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meu Perfil | IFSentral</title>

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

    .invite-item .badge {
      font-size: 0.85rem;
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
            <a href="meus-projetos.php" class="nav-link">Meus Projetos</a>
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
            <h1>Meu Perfil</h1>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="row">
          <div class="col-md-4">
            <!-- Foto de Perfil -->
            <div class="card card-primary card-outline text-center">
              <div class="card-body">
                <img class="user-profile-picture img-fluid rounded-circle mb-3" 
                     src="../assets/img/default-avatar.svg" 
                     alt="Foto de Perfil"
                     style="width: 150px; height: 150px; object-fit: cover; border: 3px solid #1B7D3D;">
                <h5 id="profile-name"><?php echo htmlspecialchars($username_logado); ?></h5>
                <p class="text-muted" id="profile-email"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
                <a href="configuracoes.php" class="btn btn-sm btn-primary">
                  <i class="fas fa-edit mr-1"></i>Editar Perfil
                </a>
              </div>
            </div>
            
            <div class="card card-primary card-outline">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-id-card mr-2"></i>Minha Conta</h3>
              </div>
              <div class="card-body">
                <p><strong>Usuario:</strong> <?php echo htmlspecialchars($username_logado); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
              </div>
            </div>
          </div>

          <div class="col-md-8">
            <div class="card card-info card-outline">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-envelope-open-text mr-2"></i>Convites Pendentes</h3>
              </div>
              <div class="card-body">
                <div id="invites-status" class="text-muted">Carregando...</div>
                <div id="invites-container"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

    <footer class="main-footer text-center">
        <strong>Copyright &copy; 2024-2025 <a href="index.html">IFSentral</a>.</strong> Todos os direitos reservados.
    </footer>
</div>

<script src="../assets/js/fetch-helpers.js"></script>
<script src="../assets/js/profile-picture-helper.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>

<script>
  $(function () {
    const API_LISTAR_CONVITES = '../api/listar_convites.php';
    const API_ACEITAR_CONVITE = '../api/aceitar_convite.php';
    const API_RECUSAR_CONVITE = '../api/recusar_convite.php';

    const invitesStatus = document.getElementById('invites-status');
    const invitesContainer = document.getElementById('invites-container');

    function formatarData(dataString) {
      if (!dataString) return '-';
      const date = new Date(dataString);
      if (Number.isNaN(date.getTime())) return '-';
      return date.toLocaleString('pt-BR');
    }

    function renderConvite(convite) {
      const wrapper = document.createElement('div');
      wrapper.className = 'invite-item border rounded p-3 mb-3';

      const statusBadge = `<span class="badge badge-warning">${convite.status}</span>`;
      wrapper.innerHTML = `
        <div class="d-flex justify-content-between align-items-start flex-wrap">
          <div class="mb-2">
            <h5 class="mb-1">${convite.project_name} ${statusBadge}</h5>
            <div class="text-muted">Convite de ${convite.inviter_name} (${convite.inviter_email})</div>
            <div class="text-muted">Permissao: ${convite.role_name}</div>
            <div class="text-muted">Enviado em ${formatarData(convite.created_at)} | Expira em ${formatarData(convite.expires_at)}</div>
          </div>
          <div class="btn-group">
            <button class="btn btn-success btn-sm" data-action="accept" data-id="${convite.id}">Aceitar</button>
            <button class="btn btn-outline-danger btn-sm" data-action="reject" data-id="${convite.id}">Recusar</button>
          </div>
        </div>
      `;
      return wrapper;
    }

    async function carregarConvites() {
      invitesStatus.textContent = 'Carregando...';
      invitesContainer.innerHTML = '';

      try {
        const response = await fetch(API_LISTAR_CONVITES, { credentials: 'include' });
        const convites = await safeJson(response);

        if (!Array.isArray(convites) || convites.length === 0) {
          invitesStatus.textContent = 'Nenhum convite pendente.';
          return;
        }

        invitesStatus.textContent = '';
        convites.forEach(convite => {
          invitesContainer.appendChild(renderConvite(convite));
        });
      } catch (error) {
        invitesStatus.innerHTML = `<div class="alert alert-danger mb-0">${error.message}</div>`;
      }
    }

    async function responderConvite(inviteId, action, buttonEl) {
      const url = action === 'accept' ? API_ACEITAR_CONVITE : API_RECUSAR_CONVITE;
      const originalText = buttonEl.textContent;

      buttonEl.disabled = true;
      buttonEl.textContent = 'Processando...';

      try {
        const response = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ invitation_id: inviteId })
        });

        await safeJson(response);
        await carregarConvites();
      } catch (error) {
        invitesStatus.innerHTML = `<div class="alert alert-danger mb-0">${error.message}</div>`;
      } finally {
        buttonEl.disabled = false;
        buttonEl.textContent = originalText;
      }
    }

    invitesContainer.addEventListener('click', function (event) {
      const button = event.target.closest('button[data-action]');
      if (!button) return;
      const action = button.getAttribute('data-action');
      const inviteId = button.getAttribute('data-id');
      responderConvite(inviteId, action, button);
    });

    carregarConvites();
  });
</script>
</body>
</html>

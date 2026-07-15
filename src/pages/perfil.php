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

  <?php require_once __DIR__ . '/../includes/header.php'; ?>

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

                <hr>

                <?php if (($profile_logado ?? 'User') === 'User'): ?>
                  <button id="btn-solicitar-moderador" type="button" class="btn btn-warning btn-block">
                    <i class="fas fa-user-shield mr-2"></i>
                    Solicitar permissão para criar projetos (Moderador)
                  </button>
                  <small class="text-muted d-block mt-2">
                    Seu pedido será analisado pelo Admin.
                  </small>
                <?php endif; ?>

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

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>
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
    const API_SOLICITACOES_PERFIL = '../api/solicitacoes_perfil.php';


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

    async function solicitarPermissaoCriador() {

      const button = document.getElementById('btn-solicitar-moderador');
      if (button) button.disabled = true;

      const motivo = prompt('Por favor, descreva brevemente por que você precisa criar projetos:');
      if (motivo === null) {
        if (button) button.disabled = false;
        return;
      }

      try {
        const res = await fetch(API_SOLICITACOES_PERFIL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ message: motivo })
        });

        const data = await safeJson(res);
        alert(data.success ? data.message : data.error);

        if (button && data.success) {
          button.textContent = 'Solicitação enviada. Aguarde...';
          button.classList.remove('btn-warning');
          button.classList.add('btn-success');
        }
      } catch (err) {
        alert('Erro de conexão ao enviar solicitação.');
      } finally {
        if (button) button.disabled = false;
      }
    }

    document.getElementById('btn-solicitar-moderador')?.addEventListener('click', solicitarPermissaoCriador);



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

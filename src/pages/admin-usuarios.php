<?php
require '../auth/auth_check.php';
require '../config/db.php';

// Descobre profile logado e o ID
$identifier = $_SESSION['user_id'] ?? $_SESSION['email'] ?? null;
$column = isset($_SESSION['user_id']) ? 'id' : 'email';

if ($identifier) {
    $stmt = $conn->prepare("SELECT id, profile FROM users WHERE $column = ? AND deletedAt IS NULL");
    $stmt->execute([$identifier]);
    $user_logado = $stmt->fetch(PDO::FETCH_ASSOC);
    $profile_logado = $user_logado['profile'] ?? 'User';
    $admin_id = $user_logado['id'] ?? 0;
} else {
    $profile_logado = 'User';
    $admin_id = 0;
}

if ($profile_logado !== 'Admin') {
    header('Location: meus-projetos.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - Gerenciar Usuários | IFSentral</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">

  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
    }

    .wrapper { display: flex; flex-direction: column; min-height: 100vh; }
    .content-wrapper { flex: 1; }

    .navbar-light .navbar-brand { color: var(--ifsc-primary) !important; }
    .card-primary .card-header { background-color: var(--ifsc-primary) !important; }
    .card-primary { border-top-color: var(--ifsc-primary) !important; }

    .card-warning.card-outline {
      border-top-color: #f39c12 !important;
    }

    .badge-warning { background-color: #f39c12; }
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
            <h1><i class="fas fa-users-cog mr-2"></i>Gerenciar Usuários</h1>
            <p class="text-muted mb-0">Aprovações de acesso a Moderador e ações administrativas.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">

        <div id="alert-container"></div>

        <!-- SOLICITAÇÕES PARA MODERADOR -->
        <h4 class="mt-4 mb-3"><i class="fas fa-user-clock mr-2"></i> Solicitações para Moderador</h4>
        <div class="card card-warning card-outline mb-5">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle m-0">
                <thead>
                  <tr>
                    <th>Data</th>
                    <th>Usuário</th>
                    <th>Email</th>
                    <th>Motivo (Mensagem)</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody id="requests-tbody">
                  <tr><td colspan="5" class="text-center">Carregando...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- GERENCIAMENTO DE USUÁRIOS -->
        <h4 class="mt-4 mb-3"><i class="fas fa-user-edit mr-2"></i> Gerenciamento de Usuários</h4>
        <div class="card card-primary card-outline mb-5">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle m-0">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Perfil</th>
                    <th>Cadastro</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody id="users-tbody">
                  <tr><td colspan="6" class="text-center">Carregando...</td></tr>
                </tbody>
              </table>
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
<script src="../assets/js/fetch-helpers.js"></script>

<script>
  const API_SOLICITACOES_PERFIL = '../api/solicitacoes_perfil.php';
  const API_ADMIN_USUARIOS = '../api/admin_usuarios.php';
  const CURRENT_ADMIN_ID = <?php echo $admin_id; ?>;

  function escapeHtml(str) {
    return (str ?? '').toString()
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function showAlert(message, type = 'info') {
    const container = document.getElementById('alert-container');
    container.innerHTML = `
      <div class="alert alert-${type} alert-dismissible fade show" role="alert">
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
        ${escapeHtml(message)}
      </div>
    `;
    container.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }

  async function loadRequests() {
    try {
      const res = await fetch(API_SOLICITACOES_PERFIL, { credentials: 'include' });
      const requests = await safeJson(res);

      const tbody = document.getElementById('requests-tbody');
      if (!Array.isArray(requests) || requests.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">Nenhuma solicitação pendente.</td></tr>';
        return;
      }

      let html = '';
      requests.forEach(r => {
        const date = r.createdAt ? new Date(r.createdAt).toLocaleDateString('pt-BR') : '-';
        const name = escapeHtml(r.name);
        const email = escapeHtml(r.email);
        const msg = r.message ? escapeHtml(r.message) : '<i>Sem mensagem</i>';

        html += `
          <tr id="req-${r.id}">
            <td>${date}</td>
            <td>${name}</td>
            <td>${email}</td>
            <td><small>${msg}</small></td>
            <td>
              <button class="btn btn-sm btn-success mr-1" onclick="answerRequest(${r.id}, 'aprovado')">
                <i class="fas fa-check"></i> Aprovar
              </button>
              <button class="btn btn-sm btn-danger" onclick="answerRequest(${r.id}, 'rejeitado')">
                <i class="fas fa-times"></i> Rejeitar
              </button>
            </td>
          </tr>
        `;
      });

      tbody.innerHTML = html;
    } catch (err) {
      console.error('Erro ao carregar solicitações', err);
      document.getElementById('requests-tbody').innerHTML = '<tr><td colspan="5" class="text-center text-danger">Erro ao carregar.</td></tr>';
    }
  }

  async function answerRequest(requestId, status) {
    if (!confirm(`Confirmar que deseja marcar a solicitação como ${status}?`)) return;

    try {
      const res = await fetch(API_SOLICITACOES_PERFIL, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ request_id: requestId, status: status })
      });

      const data = await safeJson(res);
      if (data.success) {
        const tr = document.getElementById(`req-${requestId}`);
        if (tr) tr.remove();
        showAlert(data.message, 'success');
        loadRequests();
        loadUsers();
      } else {
        showAlert(data.error || 'Erro ao processar', 'danger');
      }
    } catch (err) {
      console.error(err);
      showAlert('Erro de conexão.', 'danger');
    }
  }

  async function loadUsers() {
    const tbody = document.getElementById('users-tbody');
    try {
      // Usa a API administrativa centralizada
      const res = await fetch(API_ADMIN_USUARIOS, { credentials: 'include' });
      const users = await safeJson(res);

      if (!Array.isArray(users) || users.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Nenhum usuário encontrado.</td></tr>';
        return;
      }

      let html = '';
      users.forEach(u => {
        const created = u.createdAt ? new Date(u.createdAt).toLocaleString('pt-BR') : '-';
        const name = escapeHtml(u.name);
        const email = escapeHtml(u.email);
        const id = escapeHtml(u.id);
        
        const isSelf = parseInt(u.id) === CURRENT_ADMIN_ID;
        const disabledAttr = isSelf ? 'disabled' : '';

        html += `
          <tr>
            <td><code>${id}</code></td>
            <td>${name}</td>
            <td>${email}</td>
            <td>
              <select class="form-control form-control-sm" onchange="updateProfile(${u.id}, this.value)" ${disabledAttr}>
                  <option value="Admin" ${u.profile === 'Admin' ? 'selected' : ''}>Admin</option>
                  <option value="Moderator" ${u.profile === 'Moderator' ? 'selected' : ''}>Moderator</option>
                  <option value="User" ${u.profile === 'User' ? 'selected' : ''}>User</option>
              </select>
            </td>
            <td>${created}</td>
            <td>
              <button class="btn btn-sm btn-danger" onclick="deleteUser(${u.id})" ${disabledAttr}>
                <i class="fas fa-trash"></i> Expulsar
              </button>
            </td>
          </tr>
        `;
      });
      tbody.innerHTML = html;
    } catch (err) {
      console.error('Erro ao carregar usuários', err);
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Erro ao carregar.</td></tr>';
    }
  }

  async function updateProfile(userId, newProfile) {
    try {
      const res = await fetch(API_ADMIN_USUARIOS, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ user_id: userId, profile: newProfile })
      });
      const data = await safeJson(res);
      
      if (data.success) {
        showAlert(data.message, 'success');
      } else {
        showAlert(data.error || 'Erro ao atualizar perfil', 'danger');
        loadUsers(); // Recarrega para voltar o select ao normal
      }
    } catch (err) {
      console.error(err);
      showAlert('Erro de conexão ao atualizar perfil.', 'danger');
      loadUsers();
    }
  }

  async function deleteUser(userId) {
    if (!confirm('Atenção: Tem certeza que deseja apagar este usuário do sistema? Todos os dados dele serão ocultados.')) return;
    
    try {
      const res = await fetch(API_ADMIN_USUARIOS, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ user_id: userId })
      });
      const data = await safeJson(res);
      
      if (data.success) {
        showAlert(data.message, 'success');
        loadUsers();
      } else {
        showAlert(data.error || 'Erro ao remover usuário', 'danger');
      }
    } catch (err) {
      console.error(err);
      showAlert('Erro de conexão ao remover usuário.', 'danger');
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    loadRequests();
    loadUsers();
  });
</script>

</body>
</html>
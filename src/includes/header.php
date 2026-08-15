<?php
// src/includes/header.php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

// Descobre o que temos na sessão (ID ou Email) e carrega o profile
$identifier = $_SESSION['user_id'] ?? $_SESSION['email'] ?? null;
$column = isset($_SESSION['user_id']) ? 'id' : 'email';

if (!isset($_SESSION['profile']) && $identifier) {
    try {
        $stmt = $conn->prepare("SELECT profile FROM users WHERE $column = ? AND deletedAt IS NULL");
        $stmt->execute([$identifier]);
        $_SESSION['profile'] = $stmt->fetchColumn() ?: 'User';
    } catch (Exception $e) {
        $_SESSION['profile'] = 'User';
    }
}
$profile_logado = $_SESSION['profile'] ?? 'User';

// Define a cor e o texto da badge do usuário
$badgeClass = 'badge-secondary';
if ($profile_logado === 'Admin') $badgeClass = 'badge-danger';
if ($profile_logado === 'Moderator') $badgeClass = 'badge-warning text-dark';
?>

  <nav class="main-header navbar navbar-expand-md navbar-light navbar-white">
    <div class="container">
      <a href="/" class="navbar-brand">
        <span class="brand-text font-weight-bold">IFSentral</span>
      </a>
      <div class="collapse navbar-collapse order-3" id="navbarCollapse">
        <ul class="navbar-nav">
          <li class="nav-item"><a href="/meus-projetos" class="nav-link">Meus Projetos</a></li>
          <li class="nav-item"><a href="/explorar-projetos" class="nav-link">Explorar Projetos</a></li>
          <li class="nav-item"><a href="/documentacao" class="nav-link">Documentação</a></li>

          <?php if ($profile_logado === 'Admin'): ?>
          <li class="nav-item">
            <a href="/admin" class="nav-link font-weight-bold text-danger">Administração</a>
          </li>
          <?php endif; ?>
        </ul>
      </div>
      <ul class="order-1 order-md-3 navbar-nav navbar-no-expand ml-auto">
        <li class="nav-item dropdown">
          <a class="nav-link" data-toggle="dropdown" href="#" id="notif-bell-link">
            <i class="far fa-bell"></i>
            <span class="badge badge-danger navbar-badge" id="notif-badge" style="display:none;">0</span>
          </a>
          <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right" id="notif-dropdown-menu">
            <span class="dropdown-item dropdown-header">Notificações</span>
            <div class="dropdown-divider"></div>
            <div id="notif-list">
              <span class="dropdown-item text-muted">Carregando...</span>
            </div>
          </div>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link navbar-user-avatar d-flex align-items-center" data-toggle="dropdown" href="#">
            <i class="fas fa-user-circle mr-1" style="font-size: 1.2rem;"></i> 
            <span><?php echo htmlspecialchars($username_logado ?? 'Usuário'); ?></span>
            <span class="badge <?php echo $badgeClass; ?> ml-2" style="font-size: 0.70rem; transform: translateY(-1px);">
              <?php echo $profile_logado; ?>
            </span>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <a href="/perfil" class="dropdown-item"><i class="fas fa-user mr-2"></i> Meu Perfil</a>
            <a href="/meus-dispositivos" class="dropdown-item"><i class="fas fa-microchip mr-2"></i> Meus Sensores</a>
            <a href="/configuracoes" class="dropdown-item"><i class="fas fa-cog mr-2"></i> Configurações</a>
            <div class="dropdown-divider"></div>
            <a href="/api/logout" class="dropdown-item"><i class="fas fa-sign-out-alt mr-2 text-danger"></i> Sair</a>
          </div>
        </li>
      </ul>
    </div>
  </nav>

<script>
(function(){
  function timeAgo(dateStr){
    if (!dateStr) return '';
    var date = new Date(String(dateStr).replace(' ', 'T'));
    var diffMin = Math.floor((Date.now() - date.getTime()) / 60000);
    if (diffMin < 1) return 'agora mesmo';
    if (diffMin < 60) return diffMin + ' min atrás';
    var diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return diffH + 'h atrás';
    return Math.floor(diffH / 24) + 'd atrás';
  }

  function renderNotificacoes(data){
    var badge = document.getElementById('notif-badge');
    var list = document.getElementById('notif-list');
    var total = data.total || 0;

    if (total > 0) {
      badge.textContent = total > 99 ? '99+' : String(total);
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }

    list.innerHTML = '';

    if (!data.items || data.items.length === 0) {
      var empty = document.createElement('span');
      empty.className = 'dropdown-item text-muted';
      empty.textContent = 'Nenhuma pendência no momento.';
      list.appendChild(empty);
      return;
    }

    data.items.forEach(function(item){
      var a = document.createElement('a');
      a.href = item.link || '#';
      a.className = 'dropdown-item';

      var p = document.createElement('p');
      p.className = 'mb-0';
      p.textContent = item.title || '';

      var small = document.createElement('small');
      small.className = 'text-muted';
      small.textContent = timeAgo(item.created_at);

      a.appendChild(p);
      a.appendChild(small);
      list.appendChild(a);

      var divider = document.createElement('div');
      divider.className = 'dropdown-divider';
      list.appendChild(divider);
    });
  }

  function carregarNotificacoes(){
    fetch('/api/obter-notificacoes', { credentials: 'include' })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(data){ if (data) renderNotificacoes(data); })
      .catch(function(){ /* falha silenciosa: não interrompe a navegação da página */ });
  }

  document.addEventListener('DOMContentLoaded', function(){
    carregarNotificacoes();
    setInterval(carregarNotificacoes, 60000);
  });
})();
</script>
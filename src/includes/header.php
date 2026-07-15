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
      <a href="index.html" class="navbar-brand">
        <span class="brand-text font-weight-bold">IFSentral</span>
      </a>
      <div class="collapse navbar-collapse order-3" id="navbarCollapse">
        <ul class="navbar-nav">
          <li class="nav-item"><a href="meus-projetos.php" class="nav-link">Meus Projetos</a></li>
          <li class="nav-item"><a href="explorar_projetos.php" class="nav-link">Explorar Projetos</a></li>
          
          <?php if ($profile_logado === 'Admin'): ?>
          <li class="nav-item">
            <a href="admin-dashboard.php" class="nav-link font-weight-bold text-danger">Administração</a>
          </li>
          <?php endif; ?>
        </ul>
      </div>
      <ul class="order-1 order-md-3 navbar-nav navbar-no-expand ml-auto">
        <li class="nav-item dropdown">
          <a class="nav-link navbar-user-avatar d-flex align-items-center" data-toggle="dropdown" href="#">
            <i class="fas fa-user-circle mr-1" style="font-size: 1.2rem;"></i> 
            <span><?php echo htmlspecialchars($username_logado ?? 'Usuário'); ?></span>
            <span class="badge <?php echo $badgeClass; ?> ml-2" style="font-size: 0.70rem; transform: translateY(-1px);">
              <?php echo $profile_logado; ?>
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
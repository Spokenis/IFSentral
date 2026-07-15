<?php
require '../auth/auth_check.php';
require '../config/db.php';

// Descobre o que temos na sessão (ID ou Email)
$identifier = $_SESSION['user_id'] ?? $_SESSION['email'] ?? null;
$column = isset($_SESSION['user_id']) ? 'id' : 'email';

// Busca o perfil direto no banco de forma limpa
$stmt = $conn->prepare("SELECT profile FROM users WHERE $column = ? AND deletedAt IS NULL");
$stmt->execute([$identifier]);
$profile_logado = $stmt->fetchColumn();

// Trava de segurança absoluta
if ($profile_logado !== 'Admin') {
    header('Location: meus-projetos.php');
    exit;
}

try {
    // 1. Métricas Globais (Cards)
    $stats = [];
    $stats['users'] = $conn->query("SELECT COUNT(*) FROM users WHERE deletedAt IS NULL")->fetchColumn();
    $stats['projects'] = $conn->query("SELECT COUNT(*) FROM projects WHERE deletedAt IS NULL")->fetchColumn();
    $stats['devices'] = $conn->query("SELECT COUNT(*) FROM devices WHERE deletedAt IS NULL")->fetchColumn();
    $stats['payloads'] = $conn->query("SELECT COUNT(*) FROM device_payloads")->fetchColumn();

    // 2. Últimos Usuários Cadastrados
    $stmtUsers = $conn->query("SELECT id, name, email, profile, createdAt FROM users WHERE deletedAt IS NULL ORDER BY createdAt DESC LIMIT 5");
    $recentUsers = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

    // 3. Últimos Projetos Criados
    $stmtProjects = $conn->query("SELECT id, name, public, createdAt FROM projects WHERE deletedAt IS NULL ORDER BY createdAt DESC LIMIT 5");
    $recentProjects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);

    // 4. Contagem de Violações de Rate Limit Hoje
    $stats['violations_today'] = $conn->query("SELECT COUNT(*) FROM rate_limit_violations WHERE DATE(created_at) = CURDATE()")->fetchColumn();

} catch (PDOException $e) {
    die("Erro ao carregar dados do painel: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Painel Administrativo | IFSentral</title>

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
    .info-box-icon { color: #fff; }
  </style>
</head>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <?php require_once __DIR__ . '/../includes/header.php'; ?>

  <div class="content-wrapper">
    <div class="content-header">
      <div class="container">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0"><i class="fas fa-cogs mr-2"></i> Painel Geral do Sistema</h1>
          </div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="container">
        
        <div class="row">
          <div class="col-lg-3 col-6">
            <div class="small-box bg-info">
              <div class="inner">
                <h3><?php echo number_format($stats['users'], 0, ',', '.'); ?></h3>
                <p>Usuários Ativos</p>
              </div>
              <div class="icon"><i class="fas fa-users"></i></div>
            </div>
          </div>
          <div class="col-lg-3 col-6">
            <div class="small-box bg-success">
              <div class="inner">
                <h3><?php echo number_format($stats['projects'], 0, ',', '.'); ?></h3>
                <p>Projetos Criados</p>
              </div>
              <div class="icon"><i class="fas fa-project-diagram"></i></div>
            </div>
          </div>
          <div class="col-lg-3 col-6">
            <div class="small-box bg-warning">
              <div class="inner">
                <h3><?php echo number_format($stats['devices'], 0, ',', '.'); ?></h3>
                <p>Dispositivos (Sensores)</p>
              </div>
              <div class="icon"><i class="fas fa-microchip"></i></div>
            </div>
          </div>
          <div class="col-lg-3 col-6">
            <div class="small-box bg-danger">
              <div class="inner">
                <h3><?php echo number_format($stats['payloads'], 0, ',', '.'); ?></h3>
                <p>Total de Payloads</p>
              </div>
              <div class="icon"><i class="fas fa-database"></i></div>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-8">
            <div class="card">
              <div class="card-header border-transparent">
                <h3 class="card-title"><i class="fas fa-user-plus mr-1"></i> Usuários Recentes</h3>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table m-0 table-hover">
                    <thead>
                    <tr>
                      <th>ID</th>
                      <th>Nome</th>
                      <th>Email</th>
                      <th>Perfil</th>
                      <th>Cadastro</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentUsers as $u): ?>
                    <tr>
                      <td><code><?php echo $u['id']; ?></code></td>
                      <td><?php echo htmlspecialchars($u['name']); ?></td>
                      <td><?php echo htmlspecialchars($u['email']); ?></td>
                      <td><span class="badge <?php echo $u['profile'] === 'Admin' ? 'badge-danger' : 'badge-secondary'; ?>"><?php echo $u['profile']; ?></span></td>
                      <td><?php echo date('d/m/Y H:i', strtotime($u['createdAt'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card-header border-transparent">
                <h3 class="card-title"><i class="fas fa-folder-plus mr-1"></i> Projetos Recentes</h3>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table m-0 table-hover">
                    <thead>
                    <tr>
                      <th>ID</th>
                      <th>Nome do Projeto</th>
                      <th>Visibilidade</th>
                      <th>Criação</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentProjects as $p): ?>
                    <tr>
                      <td><code><?php echo $p['id']; ?></code></td>
                      <td><?php echo htmlspecialchars($p['name']); ?></td>
                      <td>
                        <?php if ($p['public']): ?>
                            <span class="badge badge-success">Público</span>
                        <?php else: ?>
                            <span class="badge badge-dark">Privado</span>
                        <?php endif; ?>
                      </td>
                      <td><?php echo date('d/m/Y H:i', strtotime($p['createdAt'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-toolbox mr-1"></i> Ferramentas Administrativas</h3>
              </div>
              <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                  <li class="list-group-item">
                    <a href="admin-rate-limiting.php" class="d-flex justify-content-between align-items-center text-dark">
                      <span><i class="fas fa-shield-alt text-danger mr-2"></i> Proteção Rate Limit</span>
                      <?php if($stats['violations_today'] > 0): ?>
                        <span class="badge badge-danger badge-pill" title="Violações Hoje"><?php echo $stats['violations_today']; ?> alertas</span>
                      <?php else: ?>
                        <i class="fas fa-chevron-right text-muted"></i>
                      <?php endif; ?>
                    </a>
                  </li>
                  <li class="list-group-item">
                    <a href="admin-usuarios.php" class="d-flex justify-content-between align-items-center text-dark">
                      <span><i class="fas fa-users-cog mr-2"></i> Gerenciar Usuários</span>
                      <i class="fas fa-chevron-right text-muted"></i>
                    </a>
                  </li>
                  <li class="list-group-item">
                    <a href="#mqtt-logs-section" class="d-flex justify-content-between align-items-center text-dark">
                      <span><i class="fas fa-server mr-2"></i> Logs do MQTT</span>
                      <i class="fas fa-chevron-down text-muted"></i>
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </div>

        </div>
        </div>

        <!-- Seção: Visualizador de Logs MQTT -->
        <div class="row mt-4" id="mqtt-logs-section">
          <div class="col-12">
            <div class="card card-dark">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title m-0"><i class="fas fa-terminal mr-2"></i> Monitor de Logs MQTT</h3>
                <div class="card-tools ml-auto">
                  <select id="logLimit" class="custom-select custom-select-sm d-inline-block mr-2" style="width: auto;">
                    <option value="50">50 linhas</option>
                    <option value="100" selected>100 linhas</option>
                    <option value="500">500 linhas</option>
                  </select>
                  <button id="btnRefreshLogs" class="btn btn-sm btn-primary">
                    <i class="fas fa-sync-alt"></i> Atualizar
                  </button>
                </div>
              </div>
              <div class="card-body p-0 bg-dark">
                <!-- Scroll e tipografia Mono para imitar o console -->
                <pre id="mqttLogConsole" style="height: 400px; overflow-y: auto; color: #00ff00; background-color: #121212; padding: 15px; margin: 0; font-family: 'Courier New', Courier, monospace; font-size: 14px; white-space: pre-wrap;"></pre>
              </div>
              <div class="card-footer bg-dark border-top border-secondary text-right p-2">
                <small class="text-muted" id="logStatus">Carregando...</small>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>

</div>



<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>

<script>
$(document).ready(function() {
  const logConsole = $('#mqttLogConsole');
  const logStatus = $('#logStatus');
  let autoRefreshInterval = null;

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '<')
      .replace(/>/g, '>')
      .replace(/"/g, '"')
      .replace(/'/g, '&#039;');
  }

  function loadMqttLogs() {
    const limit = $('#logLimit').val();
    logStatus.text('Atualizando...');

    $.ajax({
      url: '../api/obter_logs_mqtt.php?limit=' + limit,
      type: 'GET',
      dataType: 'json',
      success: function(response) {
        if (response.error && !response.success) {
           logConsole.html('<span class="text-danger">Erro: ' + escapeHtml(response.error) + '</span>');
           logStatus.text('Erro ao carregar.');
           return;
        }

        logConsole.empty();

        if (response.logs && response.logs.length > 0) {
          let htmlOutput = '';
          response.logs.forEach(function(line) {
            const safeLine = escapeHtml(line);
            // Realce de sintaxe básico
            if (line.includes('[ERROR]') || line.includes('[FATAL]') || line.includes('Erro')) {
              htmlOutput += '<span class="text-danger">' + safeLine + '</span>\n';
            } else if (line.includes('[WARN]') || line.includes('AVISO')) {
              htmlOutput += '<span class="text-warning">' + safeLine + '</span>\n';
            } else if (line.includes('[INFO]')) {
              htmlOutput += '<span class="text-info">' + safeLine + '</span>\n';
            } else if (line.includes('[DEBUG]')) {
              htmlOutput += '<span style="color: #aaaaaa;">' + safeLine + '</span>\n';
            } else {
              htmlOutput += safeLine + '\n';
            }
          });

          logConsole.html(htmlOutput);

          // Scroll automático
          logConsole.scrollTop(logConsole[0].scrollHeight);
          logStatus.text('Últimas ' + response.logs.length + ' linhas exibidas de ' + response.total_lines + ' no total. Atualizado em: ' + new Date().toLocaleTimeString());
        } else {
          logConsole.html('<span class="text-muted">Nenhum log registrado ainda.</span>');
          logStatus.text('Sem dados.');
        }
      },
      error: function(xhr) {
        let errorMsg = 'Erro na requisição.';
        if(xhr.responseJSON && xhr.responseJSON.error) {
            errorMsg = xhr.responseJSON.error;
        }
        logConsole.html('<span class="text-danger">' + escapeHtml(errorMsg) + '</span>');
        logStatus.text('Falha na conexão.');
      }
    });
  }

  // Event Listeners
  $('#btnRefreshLogs').click(function() {
    loadMqttLogs();
  });

  $('#logLimit').change(function() {
    loadMqttLogs();
  });

  // Carrega inicialmente
  loadMqttLogs();

  // Atualização automática a cada 5 segundos
  autoRefreshInterval = setInterval(loadMqttLogs, 5000);
});
</script>
</body>
</html>




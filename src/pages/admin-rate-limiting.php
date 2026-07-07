<?php 
require '../auth/auth_check.php';

// Verificar se é admin
if ($profile_logado !== 'Admin') {
    header('Location: meus-projetos.php');
    exit;
}

require '../config/db.php';
require '../core/RateLimiter.php';

use App\Core\RateLimiter;

// Inicializar Rate Limiter
$rateLimiter = new RateLimiter($conn);
$allSettings = $rateLimiter->getAllSettings();

// Se for POST, atualizar configuração
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_setting'])) {
    $settingKey = $_POST['setting_key'] ?? null;
    $settingValue = $_POST['setting_value'] ?? null;

    if ($settingKey && $settingValue !== null) {
        $result = $rateLimiter->updateSetting($settingKey, $settingValue);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
        
        if ($result['success']) {
            // Recarrega as settings
            $allSettings = $rateLimiter->getAllSettings();
        }
    }
}

// Buscar estatísticas de violações recentes
$sqlViolations = "SELECT device_id, COUNT(*) as total, MAX(created_at) as last_violation 
                  FROM rate_limit_violations 
                  WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  GROUP BY device_id ORDER BY total DESC LIMIT 10";
$stmtViolations = $conn->prepare($sqlViolations);
$stmtViolations->execute();
$recentViolations = $stmtViolations->fetchAll(PDO::FETCH_ASSOC);

// Buscar dispositivos com mais requisições
$sqlTopDevices = "SELECT device_id, COUNT(*) as requests, MAX(created_at) as last_request
                  FROM device_payloads 
                  WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  GROUP BY device_id ORDER BY requests DESC LIMIT 10";
$stmtTopDevices = $conn->prepare($sqlTopDevices);
$stmtTopDevices->execute();
$topDevices = $stmtTopDevices->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - Rate Limiting | IFSentral</title>

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
    .btn-primary { background-color: var(--ifsc-primary) !important; border-color: var(--ifsc-primary) !important; }
    .btn-primary:hover { background-color: var(--ifsc-secondary) !important; }
    .card-primary .card-header { background-color: var(--ifsc-primary) !important; }
    .badge-warning { background-color: #ffc107; color: #000; }
  </style>
</head>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <nav class="main-header navbar navbar-expand-md navbar-light navbar-white">
    <div class="container">
      <a href="index.html" class="navbar-brand">
        <span class="brand-text font-weight-bold">IFSentral</span>
      </a>
      <button class="navbar-toggler order-1" type="button" data-toggle="collapse" data-target="#navbarCollapse">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse order-3" id="navbarCollapse">
        <ul class="navbar-nav">
          <li class="nav-item"><a href="meus-projetos.php" class="nav-link">Meus Projetos</a></li>
          <li class="nav-item"><a href="admin-rate-limiting.php" class="nav-link active">Admin - Rate Limit</a></li>
        </ul>
      </div>
    </div>
  </nav>
  
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <h1><i class="fas fa-cogs mr-2"></i>Administração - Rate Limiting</h1>
        <p class="text-muted">Configure limites de requisições por dispositivo</p>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <?php if ($message): ?>
          <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
          </div>
        <?php endif; ?>

        <div class="row">
          <div class="col-lg-8">
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-sliders-h mr-2"></i>Configurações Globais</h3>
              </div>
              <div class="card-body">
                <form method="POST" action="">
                  <div class="form-group">
                    <label for="rate_limit_enabled">Habilitar Rate Limiting?</label>
                    <select id="rate_limit_enabled" name="setting_value" class="form-control" onchange="updateSetting('RATE_LIMIT_ENABLED', this.value)">
                      <option value="1" <?php echo $allSettings['RATE_LIMIT_ENABLED'] == '1' ? 'selected' : ''; ?>>✓ Habilitado</option>
                      <option value="0" <?php echo $allSettings['RATE_LIMIT_ENABLED'] == '0' ? 'selected' : ''; ?>>✗ Desabilitado</option>
                    </select>
                  </div>

                  <div class="form-group">
                    <label for="requests_per_minute">Requisições por Minuto (Padrão)</label>
                    <input type="number" id="requests_per_minute" name="setting_value" class="form-control" 
                           value="<?php echo htmlspecialchars($allSettings['RATE_LIMIT_REQUESTS_PER_MINUTE'] ?? 60); ?>" min="1" max="10000">
                    <small class="form-text text-muted">Máximo de requisições HTTP por minuto por dispositivo</small>
                  </div>

                  <div class="form-group">
                    <label for="window_minutes">Janela de Tempo (minutos)</label>
                    <input type="number" id="window_minutes" name="setting_value" class="form-control"
                           value="<?php echo htmlspecialchars($allSettings['RATE_LIMIT_WINDOW_MINUTES'] ?? 1); ?>" min="1" max="60">
                    <small class="form-text text-muted">Período para contagem de requisições</small>
                  </div>

                  <div class="form-group">
                    <label for="soft_limit">Percentual de Aviso (Soft Limit)</label>
                    <input type="number" id="soft_limit" name="setting_value" class="form-control"
                           value="<?php echo htmlspecialchars($allSettings['RATE_LIMIT_SOFT_LIMIT_PERCENT'] ?? 80); ?>" min="0" max="100">
                    <small class="form-text text-muted">Percentual do limite para começar a alertar (ex: 80%)</small>
                  </div>

                  <div class="form-group">
                    <label for="log_violations">Registrar Violações</label>
                    <select id="log_violations" name="setting_value" class="form-control" onchange="updateSetting('LOG_RATE_LIMIT_VIOLATIONS', this.value)">
                      <option value="1" <?php echo $allSettings['LOG_RATE_LIMIT_VIOLATIONS'] == '1' ? 'selected' : ''; ?>>✓ Ativado</option>
                      <option value="0" <?php echo $allSettings['LOG_RATE_LIMIT_VIOLATIONS'] == '0' ? 'selected' : ''; ?>>✗ Desativado</option>
                    </select>
                  </div>

                  <input type="hidden" name="setting_key" id="setting_key" value="">
                  <input type="hidden" name="update_setting" value="1">
                  <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-2"></i>Salvar Configuração</button>
                </form>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-info">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Status do Sistema</h3>
              </div>
              <div class="card-body">
                <p><strong>Rate Limiting:</strong>
                  <?php if ($allSettings['RATE_LIMIT_ENABLED'] == '1'): ?>
                    <span class="badge badge-success">Ativado</span>
                  <?php else: ?>
                    <span class="badge badge-danger">Desativado</span>
                  <?php endif; ?>
                </p>
                <p><strong>Limite Padrão:</strong> <span class="badge badge-primary"><?php echo $allSettings['RATE_LIMIT_REQUESTS_PER_MINUTE']; ?> req/min</span></p>
                <p><strong>Janela:</strong> <span class="badge badge-secondary"><?php echo $allSettings['RATE_LIMIT_WINDOW_MINUTES']; ?> min</span></p>
                <hr>
                <p><i class="fas fa-exclamation-triangle text-warning mr-2"></i><strong>Nota:</strong> Use os inputs acima para atualizar cada configuração individualmente.</p>
              </div>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-lg-6">
            <div class="card card-warning">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Violações (Últimas 24h)</h3>
              </div>
              <div class="card-body">
                <?php if (!empty($recentViolations)): ?>
                  <table class="table table-sm">
                    <thead>
                      <tr><th>Device ID</th><th>Violações</th><th>Última</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($recentViolations as $v): ?>
                        <tr>
                          <td><span class="badge badge-danger"><?php echo $v['device_id']; ?></span></td>
                          <td><?php echo $v['total']; ?></td>
                          <td><?php echo date('H:i', strtotime($v['last_violation'])); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <p class="text-muted">Nenhuma violação registrada nas últimas 24 horas</p>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-success">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Top Dispositivos (Última Hora)</h3>
              </div>
              <div class="card-body">
                <?php if (!empty($topDevices)): ?>
                  <table class="table table-sm">
                    <thead>
                      <tr><th>Device ID</th><th>Requisições</th><th>Última</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($topDevices as $d): ?>
                        <tr>
                          <td><span class="badge badge-info"><?php echo $d['device_id']; ?></span></td>
                          <td><?php echo $d['requests']; ?></td>
                          <td><?php echo date('H:i', strtotime($d['last_request'])); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <p class="text-muted">Sem requisições na última hora</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>
  </div>

  <footer class="main-footer text-center">
    <strong>Copyright &copy; 2024-2026 <a href="index.html">IFSentral</a>.</strong>
  </footer>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateSetting(key, value) {
  document.getElementById('setting_key').value = key;
  document.querySelector('input[name="setting_value"][type="text"], input[name="setting_value"][type="number"], select[name="setting_value"]').value = value;
}

// Auto-update quando mudar os selects
document.querySelector('select[name="setting_value"]').addEventListener('change', function(e) {
  const key = e.target.id === 'rate_limit_enabled' ? 'RATE_LIMIT_ENABLED' : 'LOG_RATE_LIMIT_VIOLATIONS';
  document.getElementById('setting_key').value = key;
  document.querySelector('form').submit();
});
</script>
</body>
</html>

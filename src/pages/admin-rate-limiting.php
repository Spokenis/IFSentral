<?php 
require '../auth/auth_check.php';
require '../config/db.php';

// Verificar se é admin (carrega profile da sessão/banco antes do header.php)
$identifier = $_SESSION['user_id'] ?? $_SESSION['email'] ?? null;
$column = isset($_SESSION['user_id']) ? 'id' : 'email';

if ($identifier) {
    $stmt = $conn->prepare("SELECT profile FROM users WHERE $column = ? AND deletedAt IS NULL");
    $stmt->execute([$identifier]);
    $profile_logado = $stmt->fetchColumn() ?: 'User';
} else {
    $profile_logado = 'User';
}

if ($profile_logado !== 'Admin') {
    header('Location: ' . app_url('meus-projetos'));
    exit;
}

require '../core/RateLimiter.php';
require '../core/Csrf.php';

use App\Core\RateLimiter;
use App\Core\Csrf;


// Inicializar Rate Limiter
$rateLimiter = new RateLimiter($conn);
$allSettings = $rateLimiter->getAllSettings();

// Configurações editáveis nesta tela: chave => [rótulo, mínimo, máximo]
$editableSettings = [
    'RATE_LIMIT_ENABLED'             => ['Habilitar Rate Limiting', 0, 1],
    'RATE_LIMIT_REQUESTS_PER_MINUTE' => ['Requisições por Minuto', 1, 10000],
    'RATE_LIMIT_WINDOW_MINUTES'      => ['Janela de Tempo', 1, 60],
    'RATE_LIMIT_SOFT_LIMIT_PERCENT'  => ['Percentual de Aviso', 0, 100],
    'LOG_RATE_LIMIT_VIOLATIONS'      => ['Registrar Violações', 0, 1],
];

// Se for POST, atualizar configurações (o formulário envia todas como settings[CHAVE])
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_setting'])) {
    Csrf::requireValidTokenFromPost();

    $submitted = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
    $errors = [];
    $changed = [];

    foreach ($editableSettings as $key => [$label, $min, $max]) {
        if (!isset($submitted[$key])) {
            continue;
        }
        $value = filter_var($submitted[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($value === false) {
            $errors[] = "\"$label\" deve ser um número entre $min e $max.";
            continue;
        }
        // UPDATE sem mudança devolve rowCount 0, que o RateLimiter trata como erro
        if ((string)$value !== (string)($allSettings[$key] ?? '')) {
            $changed[$key] = (string)$value;
        }
    }

    if ($errors) {
        $message = implode(' ', $errors);
        $messageType = 'danger';
    } elseif (!$changed) {
        $message = 'Nenhuma alteração para salvar.';
        $messageType = 'info';
    } else {
        foreach ($changed as $key => $value) {
            $result = $rateLimiter->updateSetting($key, $value);
            if (!$result['success']) {
                $errors[] = $editableSettings[$key][0] . ': ' . $result['message'];
            }
        }
        $message = $errors ? implode(' ', $errors) : 'Configurações atualizadas.';
        $messageType = $errors ? 'danger' : 'success';

        // Recarrega as settings
        $allSettings = $rateLimiter->getAllSettings();
    }
}

// ---------- Proteção do login (tentativas erradas por conta e por IP) ----------
$loginCampos = [
    'LOGIN_RATE_LIMIT_ENABLED'        => ['Proteção do login', 0, 1, 'Bloquear login após tentativas erradas (1=sim, 0=não)'],
    'LOGIN_RATE_LIMIT_MAX_ATTEMPTS'   => ['Tentativas por conta', 1, 100, 'Tentativas erradas por conta antes do bloqueio'],
    'LOGIN_RATE_LIMIT_WINDOW_MINUTES' => ['Tempo de bloqueio', 1, 1440, 'Janela (minutos) em que as tentativas erradas são contadas'],
    'LOGIN_RATE_LIMIT_MAX_PER_IP'     => ['Tentativas por IP', 0, 100000, 'Tentativas erradas por IP antes do bloqueio (0 = sem limite por IP)'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_login'])) {
    Csrf::requireValidTokenFromPost();
    $enviados = is_array($_POST['login'] ?? null) ? $_POST['login'] : [];
    $errosLogin = [];
    $novos = [];
    foreach ($loginCampos as $key => [$label, $min, $max, $desc]) {
        $v = filter_var($enviados[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($v === false) { $errosLogin[] = "\"$label\" deve ser um número entre $min e $max."; continue; }
        $novos[$key] = [(string)$v, $desc];
    }
    [$listaIps, $invalidos] = RateLimiter::normalizarListaIps((string)($enviados['LOGIN_RATE_LIMIT_IP_ISENTOS'] ?? ''));
    if ($invalidos) {
        $errosLogin[] = 'IPs/redes inválidos: ' . implode(', ', array_slice($invalidos, 0, 5)) . '. Use o formato 10.50.0.0/16 ou 192.168.1.20.';
    } elseif (strlen($listaIps) > 500) {
        $errosLogin[] = 'A lista de IPs isentos passa de 500 caracteres; use redes (ex.: 10.50.0.0/16).';
    }
    if ($errosLogin) {
        $message = implode(' ', $errosLogin);
        $messageType = 'danger';
    } else {
        $novos['LOGIN_RATE_LIMIT_IP_ISENTOS'] = [$listaIps, 'IPs/redes isentos do limite por IP (o limite por conta continua valendo)'];
        $falhou = false;
        foreach ($novos as $key => [$v, $desc]) {
            $falhou = !$rateLimiter->salvarConfiguracao($key, $v, $desc) || $falhou;
        }
        $message = $falhou ? 'Não foi possível salvar todas as configurações do login.' : 'Proteção do login atualizada.';
        $messageType = $falhou ? 'danger' : 'success';
    }
    $allSettings = $rateLimiter->getAllSettings();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['desbloquear'])) {
    Csrf::requireValidTokenFromPost();
    $tipo = (string)$_POST['desbloquear'];
    $alvo = (string)($_POST['alvo'] ?? '');
    $n = $rateLimiter->desbloquearLogin($tipo, $alvo);
    $message = $tipo === 'todos' ? "Todos os bloqueios de login foram removidos ($n tentativas erradas apagadas)."
        : ($tipo === 'ip' ? 'IP' : 'Conta') . ' desbloqueado(a): ' . $alvo . '.';
    $messageType = 'success';
}
$loginCfg = $rateLimiter->loginConfig();
$loginBloqueios = $rateLimiter->bloqueiosLogin();
$ipAtual = $_SERVER['REMOTE_ADDR'] ?? '';

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
  <title>Admin - Rate Limiting | IFSCentral Lite</title>

  <link rel="stylesheet" href="assets/vendor/fonts/source-sans-pro.css">
  <link rel="stylesheet" href="assets/vendor/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/vendor/admin-lte/3.2.0/css/adminlte.min.css">
  
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

  <?php require_once __DIR__ . '/../includes/header.php'; ?>
  
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <h1><i class="fas fa-cogs mr-2"></i>Administração - Rate Limiting</h1>
        <p class="text-muted">Limites de requisições dos dispositivos e proteção do login</p>
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
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Csrf::getToken()); ?>">
                  <div class="form-group">
                    <label for="rate_limit_enabled">Habilitar Rate Limiting?</label>
                    <select id="rate_limit_enabled" name="settings[RATE_LIMIT_ENABLED]" class="form-control">
                      <option value="1" <?php echo $allSettings['RATE_LIMIT_ENABLED'] == '1' ? 'selected' : ''; ?>>✓ Habilitado</option>
                      <option value="0" <?php echo $allSettings['RATE_LIMIT_ENABLED'] == '0' ? 'selected' : ''; ?>>✗ Desabilitado</option>
                    </select>
                  </div>

                  <div class="form-group">
                    <label for="requests_per_minute">Requisições por Minuto (Padrão)</label>
                    <input type="number" id="requests_per_minute" name="settings[RATE_LIMIT_REQUESTS_PER_MINUTE]" class="form-control" 
                           value="<?php echo htmlspecialchars($allSettings['RATE_LIMIT_REQUESTS_PER_MINUTE'] ?? 60); ?>" min="1" max="10000">
                    <small class="form-text text-muted">Máximo de requisições HTTP por minuto por dispositivo</small>
                  </div>

                  <div class="form-group">
                    <label for="window_minutes">Janela de Tempo (minutos)</label>
                    <input type="number" id="window_minutes" name="settings[RATE_LIMIT_WINDOW_MINUTES]" class="form-control"
                           value="<?php echo htmlspecialchars($allSettings['RATE_LIMIT_WINDOW_MINUTES'] ?? 1); ?>" min="1" max="60">
                    <small class="form-text text-muted">Período para contagem de requisições</small>
                  </div>

                  <div class="form-group">
                    <label for="soft_limit">Percentual de Aviso (Soft Limit)</label>
                    <input type="number" id="soft_limit" name="settings[RATE_LIMIT_SOFT_LIMIT_PERCENT]" class="form-control"
                           value="<?php echo htmlspecialchars($allSettings['RATE_LIMIT_SOFT_LIMIT_PERCENT'] ?? 80); ?>" min="0" max="100">
                    <small class="form-text text-muted">Percentual do limite para começar a alertar (ex: 80%)</small>
                  </div>

                  <div class="form-group">
                    <label for="log_violations">Registrar Violações</label>
                    <select id="log_violations" name="settings[LOG_RATE_LIMIT_VIOLATIONS]" class="form-control">
                      <option value="1" <?php echo $allSettings['LOG_RATE_LIMIT_VIOLATIONS'] == '1' ? 'selected' : ''; ?>>✓ Ativado</option>
                      <option value="0" <?php echo $allSettings['LOG_RATE_LIMIT_VIOLATIONS'] == '0' ? 'selected' : ''; ?>>✗ Desativado</option>
                    </select>
                  </div>

                  <input type="hidden" name="update_setting" value="1">
                  <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-2"></i>Salvar Configurações</button>
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
                              </div>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-lg-8">
            <div class="card card-danger card-outline" id="protecao-login">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-user-lock mr-2"></i>Proteção do login</h3>
              </div>
              <div class="card-body">
                <p class="text-muted small mb-3">
                  Bloqueia temporariamente o login depois de várias senhas erradas. O limite <strong>por conta</strong>
                  protege cada usuário; o limite <strong>por IP</strong> barra quem testa muitas contas. Na rede do câmpus
                  muitos usuários saem pelo mesmo IP: aumente o limite por IP, desligue-o (0) ou isente as redes do câmpus.
                </p>
                <form method="POST" action="">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Csrf::getToken()); ?>">
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label for="login_enabled">Proteção do login</label>
                      <select id="login_enabled" name="login[LOGIN_RATE_LIMIT_ENABLED]" class="form-control">
                        <option value="1" <?php echo $loginCfg['ativo'] ? 'selected' : ''; ?>>✓ Ativada</option>
                        <option value="0" <?php echo !$loginCfg['ativo'] ? 'selected' : ''; ?>>✗ Desativada</option>
                      </select>
                    </div>
                    <div class="form-group col-md-6">
                      <label for="login_janela">Tempo de bloqueio (minutos)</label>
                      <input type="number" id="login_janela" name="login[LOGIN_RATE_LIMIT_WINDOW_MINUTES]" class="form-control" min="1" max="1440"
                             value="<?php echo (int)$loginCfg['janela']; ?>">
                      <small class="form-text text-muted">As tentativas erradas contam durante este tempo</small>
                    </div>
                    <div class="form-group col-md-6">
                      <label for="login_conta">Tentativas erradas por conta</label>
                      <input type="number" id="login_conta" name="login[LOGIN_RATE_LIMIT_MAX_ATTEMPTS]" class="form-control" min="1" max="100"
                             value="<?php echo (int)$loginCfg['por_conta']; ?>">
                    </div>
                    <div class="form-group col-md-6">
                      <label for="login_ip">Tentativas erradas por IP</label>
                      <input type="number" id="login_ip" name="login[LOGIN_RATE_LIMIT_MAX_PER_IP]" class="form-control" min="0" max="100000"
                             value="<?php echo (int)$loginCfg['por_ip']; ?>">
                      <small class="form-text text-muted">Somando todas as contas; 0 = sem limite por IP</small>
                    </div>
                  </div>
                  <div class="form-group">
                    <label for="login_isentos">IPs ou redes isentos do limite por IP</label>
                    <textarea id="login_isentos" name="login[LOGIN_RATE_LIMIT_IP_ISENTOS]" class="form-control" rows="2" maxlength="600"
                              placeholder="ex.: 10.50.0.0/16, 192.168.1.20"><?php echo htmlspecialchars($loginCfg['ips_isentos']); ?></textarea>
                    <small class="form-text text-muted">
                      Separe por vírgula. O limite por conta continua valendo para eles.
                      Seu IP agora: <code><?php echo htmlspecialchars($ipAtual); ?></code>
                    </small>
                  </div>
                  <input type="hidden" name="update_login" value="1">
                  <button type="submit" class="btn btn-danger"><i class="fas fa-save mr-2"></i>Salvar proteção do login</button>
                </form>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-secondary card-outline">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-ban mr-2"></i>Bloqueados agora</h3>
              </div>
              <div class="card-body">
                <?php if (empty($loginBloqueios['contas']) && empty($loginBloqueios['ips'])): ?>
                  <p class="text-muted mb-0">Nenhuma conta ou IP bloqueado.</p>
                <?php else: ?>
                  <table class="table table-sm">
                    <tbody>
                      <?php foreach (['contas' => 'conta', 'ips' => 'ip'] as $grupo => $tipo): ?>
                        <?php foreach ($loginBloqueios[$grupo] as $b): ?>
                          <tr>
                            <td class="text-break">
                              <i class="fas <?php echo $tipo === 'ip' ? 'fa-network-wired' : 'fa-user'; ?> text-muted mr-1"></i>
                              <?php echo htmlspecialchars($b['alvo']); ?>
                              <br><small class="text-muted"><?php echo (int)$b['falhas']; ?> erradas, última às <?php echo date('H:i', strtotime($b['ultima'])); ?></small>
                            </td>
                            <td class="text-right align-middle">
                              <form method="POST" action="" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Csrf::getToken()); ?>">
                                <input type="hidden" name="desbloquear" value="<?php echo $tipo; ?>">
                                <input type="hidden" name="alvo" value="<?php echo htmlspecialchars($b['alvo']); ?>">
                                <button type="submit" class="btn btn-xs btn-outline-success" title="Desbloquear"><i class="fas fa-unlock"></i></button>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Csrf::getToken()); ?>">
                    <input type="hidden" name="desbloquear" value="todos">
                    <button type="submit" class="btn btn-sm btn-success btn-block"><i class="fas fa-unlock-alt mr-1"></i>Desbloquear todos</button>
                  </form>
                <?php endif; ?>
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

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>

</div>

<script src="assets/vendor/jquery/3.7.1/jquery.min.js"></script>
<script src="assets/vendor/bootstrap/4.6.2/bootstrap.bundle.min.js"></script>
<script src="assets/js/input-limits.js"></script>
<script src="assets/js/profile-picture-helper.js"></script>
</body>
</html>

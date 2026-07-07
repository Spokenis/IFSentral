<?php 
require '../auth/auth_check.php';
require '../config/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: meus-projetos.php');
    exit;
}
$project_id_from_url = intval($_GET['id']);

// Obter user_id da sessão ou do banco de dados basado no email
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id && isset($_SESSION['email'])) {
    try {
        $sql = "SELECT id FROM users WHERE email = ? AND deletedAt IS NULL";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_SESSION['email']]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $user_id = $user_data['id'];
        }
    } catch (Exception $e) {
        header('Location: meus-projetos.php');
        exit;
    }
}

if (!$user_id) {
    header('Location: meus-projetos.php');
    exit;
}

// Validar se o usuário tem permissão para acessar este projeto
try {
    $sql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id_from_url, $user_id]);
    if ($stmt->rowCount() == 0) {
        header('Location: meus-projetos.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: meus-projetos.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gerenciar Projeto | IFSentral</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
  
  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
      --ifsc-light: #2A9B4A;
    }
    
    .wrapper { display: flex; flex-direction: column; min-height: 100vh; }
    .content-wrapper { flex: 1; }
    .card-primary.card-tabs .nav-tabs .nav-link:not(.active) { color: rgba(255, 255, 255, 0.8); }
    .card-primary.card-tabs .nav-tabs .nav-link:not(.active):hover { color: #ffffff; }
    #project-tags-container .badge {
        font-size: 0.9rem;
        margin-right: 5px;
        color: white !important;
    }
    .badge-warning { color: #212529 !important; }
    #grafico-visualizacao-container {
        min-height: 350px;
        height: 350px;
        max-height: 350px;
        position: relative;
    }
    .grafico-actions .btn {
      margin-left: 5px;
    }
    
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
    
    /* Estilos para formulário de gráficos */
    .dataset-item {
      background: #f8f9fa;
      border: 1px solid #ddd;
      border-radius: 5px;
      padding: 15px;
      margin-bottom: 15px;
      position: relative;
    }
    
    .dataset-item.selected {
      background: #d4edda;
      border-color: var(--ifsc-primary);
    }
    
    .dataset-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px;
    }
    
    .btn-remove-dataset {
      position: absolute;
      top: 10px;
      right: 10px;
      padding: 5px 10px;
      font-size: 0.8rem;
    }
    
    .preview-chart {
      background: #f8f9fa;
      border: 2px dashed #ddd;
      border-radius: 5px;
      padding: 20px;
      min-height: 300px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .time-range-buttons {
      display: flex;
      gap: 10px;
      margin-bottom: 15px;
    }
    
    .time-range-buttons .btn {
      flex: 1;
    }
    
    .time-range-buttons .btn.active {
      background-color: var(--ifsc-primary) !important;
      color: white !important;
    }
    
    /* Fullscreen styles */
    .card:fullscreen {
      display: flex;
      flex-direction: column;
      padding: 20px;
      background: white;
      overflow: auto;
    }
    
    .card:fullscreen .card-body {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    
    .card:fullscreen canvas {
      min-height: 80vh !important;
      max-height: 80vh !important;
    }
    
    /* Tooltip personalizado */
    .chartjs-tooltip {
      opacity: 1;
      position: absolute;
      background: rgba(0, 0, 0, 0.8);
      color: white;
      border-radius: 3px;
      pointer-events: none;
      transform: translate(-50%, 0);
      transition: all .1s ease;
      z-index: 1000;
    }
    
    /* Cursor para gráficos com zoom */
    canvas {
      cursor: crosshair;
    }
    
    canvas:active {
      cursor: grabbing;
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
          <li class="nav-item"><a href="meus-projetos.php" class="nav-link">Meus Projetos</a></li>
          <li class="nav-item"><a href="explorar_projetos.php" class="nav-link">Explorar Projetos</a></li>
          <li class="nav-item"><a href="documentacao.php" class="nav-link">Documentação da API</a></li>
        </ul>
      </div>
      <ul class="order-1 order-md-3 navbar-nav navbar-no-expand ml-auto">
        <li class="nav-item dropdown">
          <a class="nav-link navbar-user-avatar" data-toggle="dropdown" href="#">
            <i class="fas fa-user-circle"></i>
            <span><?php echo htmlspecialchars($username_logado); ?></span>
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
            <h1 id="project-title" style="display: inline-block; margin-right: 15px;">Carregando projeto...</h1>
            <span id="project-tags-container"></span> 
            <p class="text-muted">Retornando de <a href="meus-projetos.php">Meus Projetos</a></p>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="card card-primary card-tabs">
          <div class="card-header p-0 pt-1">
            <ul class="nav nav-tabs" id="project-tabs" role="tablist">
              <li class="nav-item"><a class="nav-link active" id="tab-geral-tab" data-toggle="pill" href="#tab-geral" role="tab"><i class="fas fa-chart-line mr-1"></i>Visão Geral</a></li>
              <li class="nav-item"><a class="nav-link" id="tab-graficos-tab" data-toggle="pill" href="#tab-graficos" role="tab"><i class="fas fa-chart-bar mr-1"></i>Gráficos</a></li>
              <li class="nav-item"><a class="nav-link" id="tab-sensores-tab" data-toggle="pill" href="#tab-sensores" role="tab"><i class="fas fa-microchip mr-1"></i>Dispositivos</a></li>
              <li class="nav-item"><a class="nav-link" id="tab-participantes-tab" data-toggle="pill" href="#tab-participantes" role="tab"><i class="fas fa-users mr-1"></i>Participantes</a></li>
              <li class="nav-item"><a class="nav-link" id="tab-solicitacoes-tab" data-toggle="pill" href="#tab-solicitacoes" role="tab"><i class="fas fa-inbox mr-1"></i>Solicitações <span class="badge badge-warning" id="badge-solicitacoes" style="display: none;">0</span></a></li>
              <li class="nav-item"><a class="nav-link" id="tab-config-tab" data-toggle="pill" href="#tab-config" role="tab"><i class="fas fa-cog mr-1"></i>Configurações</a></li>
            </ul>
          </div>
          <div class="card-body">
            <div class="tab-content" id="project-tabs-content">
              
              <div class="tab-pane fade show active" id="tab-geral" role="tabpanel">
                <div class="row">
                  <div class="col-12 col-sm-6 col-md-3"><div class="info-box"><span class="info-box-icon bg-info elevation-1"><i class="fas fa-microchip"></i></span><div class="info-box-content"><span class="info-box-text">Dispositivos</span><span class="info-box-number" id="info-box-devices">...</span></div></div></div>
                  <div class="col-12 col-sm-6 col-md-3"><div class="info-box mb-3"><span class="info-box-icon bg-danger elevation-1"><i class="fas fa-users"></i></span><div class="info-box-content"><span class="info-box-text">Participantes</span><span class="info-box-number" id="info-box-participantes">...</span></div></div></div>
                  <div class="col-12 col-sm-6 col-md-3"><div class="info-box mb-3"><span class="info-box-icon bg-success elevation-1"><i class="fas fa-chart-bar"></i></span><div class="info-box-content"><span class="info-box-text">Gráficos</span><span class="info-box-number" id="info-box-graficos">...</span></div></div></div>
                  <div class="col-12 col-sm-6 col-md-3"><div class="info-box mb-3"><span class="info-box-icon bg-warning elevation-1"><i class="fas fa-database"></i></span><div class="info-box-content"><span class="info-box-text">Última Leitura</span><span class="info-box-number" id="info-box-ultima-leitura" style="font-size: 0.9rem;">...</span></div></div></div>
                </div>
                
                <div class="row">
                  <div class="col-md-12">
                    <div class="card card-primary">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Informações do Projeto</h3>
                      </div>
                      <div class="card-body">
                        <div class="row">
                          <div class="col-md-6">
                            <p><strong>Nome:</strong> <span id="geral-proj-name">Carregando...</span></p>
                            <p><strong>Descrição:</strong></p>
                            <p id="geral-proj-desc" style="font-style: italic;">Carregando...</p>
                          </div>
                          <div class="col-md-6">
                            <p><strong>Tags:</strong></p>
                            <div id="geral-tags-container" style="margin-top: 10px;">Carregando...</div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                
                <div class="row">
                  <div class="col-md-12">
                    <div class="card">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>Gráficos Salvos</h3>
                      </div>
                      <div class="card-body">
                        <div id="graficos-salvos-status" style="padding: 10px;">Carregando...</div>
                        <div id="graficos-salvos-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 15px;">
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="tab-pane fade" id="tab-graficos" role="tabpanel">
                <div class="row">
                  <div class="col-lg-6">
                    <div class="card card-primary">
                      <div class="card-header">
                        <h3 class="card-title">Criar Novo Gráfico</h3>
                      </div>
                      <form id="form-grafico">
                        <div class="card-body">
                          
                          <div class="form-group">
                            <label for="chart-name">Nome do Gráfico:</label>
                            <input type="text" id="chart-name" class="form-control" placeholder="Ex: Temperatura vs Umidade" required>
                          </div>
                          
                          <div class="form-group">
                            <label for="chart-type">Tipo de Gráfico:</label>
                            <select id="chart-type" class="form-control" required>
                              <option value="linha">Linha</option>
                              <option value="barra">Barra</option>
                              <option value="area">Área</option>
                              <option value="scatter">Dispersão</option>
                            </select>
                          </div>
                          
                          <div class="form-group">
                            <label>Intervalo de Tempo:</label>
                            <div class="time-range-buttons">
                              <button type="button" class="btn btn-outline-primary time-range-btn" data-range="24h">Últimas 24h</button>
                              <button type="button" class="btn btn-outline-primary time-range-btn" data-range="7d">7 dias</button>
                              <button type="button" class="btn btn-outline-primary time-range-btn" data-range="30d">30 dias</button>
                              <button type="button" class="btn btn-outline-primary time-range-btn" data-range="all">Todos</button>
                            </div>
                          </div>
                          
                          <div class="form-row" id="custom-dates" style="display:none;">
                            <div class="form-group col-md-6">
                              <label for="date-start">Data Início:</label>
                              <input type="datetime-local" id="date-start" class="form-control">
                            </div>
                            <div class="form-group col-md-6">
                              <label for="date-end">Data Fim:</label>
                              <input type="datetime-local" id="date-end" class="form-control">
                            </div>
                          </div>
                          
                          <hr>
                          
                          <div class="form-group">
                            <label for="select-device">Selecionar Dispositivo:</label>
                            <select id="select-device" class="form-control select2" style="width: 100%;">
                              <option value="">-- Escolha um dispositivo --</option>
                            </select>
                          </div>
                          
                          <div class="form-group">
                            <label for="select-variable">Selecionar Variável:</label>
                            <select id="select-variable" class="form-control" disabled>
                              <option value="">-- Carregando opções --</option>
                            </select>
                          </div>
                          
                          <div class="form-row">
                            <div class="form-group col-md-6">
                              <label for="variable-axis">Eixo:</label>
                              <select id="variable-axis" class="form-control">
                                <option value="y">Y (Valores)</option>
                                <option value="x">X (Tempo/Categoria)</option>
                              </select>
                            </div>
                            <div class="form-group col-md-6">
                              <label for="variable-color">Cor:</label>
                              <input type="color" id="variable-color" class="form-control" value="#1B7D3D">
                            </div>
                          </div>
                          
                          <button type="button" class="btn btn-info btn-block" id="btn-add-dataset">
                            <i class="fas fa-plus mr-2"></i> Adicionar Dataset
                          </button>
                          
                        </div>
                      </form>
                    </div>
                  </div>
                  
                  <div class="col-lg-6">
                    <div class="card">
                      <div class="card-header bg-secondary">
                        <h3 class="card-title">Preview</h3>
                      </div>
                      <div class="card-body">
                        <div id="preview-message" class="preview-chart">
                          <div class="text-center text-muted">
                            <p><i class="fas fa-chart-line fa-3x mb-3"></i></p>
                            <p>Adicione datasets para visualizar preview</p>
                          </div>
                        </div>
                      </div>
                    </div>
                    
                    <div class="card">
                      <div class="card-header bg-info">
                        <h3 class="card-title">Datasets Adicionados</h3>
                      </div>
                      <div class="card-body" id="datasets-list">
                        <p class="text-muted">Nenhum dataset adicionado ainda.</p>
                      </div>
                    </div>
                  </div>
                </div>
                
                <div class="row mt-3">
                  <div class="col-12">
                    <div class="form-check mb-3">
                      <input type="checkbox" class="form-check-input" id="chart-is-public">
                      <label class="form-check-label" for="chart-is-public">
                        <i class="fas fa-globe mr-2"></i>Tornar este gráfico público (visível em projetos explorados)
                      </label>
                    </div>
                    <button type="button" class="btn btn-primary btn-lg btn-block" id="btn-salvar-grafico">
                      <i class="fas fa-save mr-2"></i> Salvar Gráfico
                    </button>
                  </div>
                </div>
              </div>

              <div class="tab-pane fade" id="tab-sensores" role="tabpanel">
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-microchip mr-2"></i>Dispositivos do Projeto</h3>
                    <div class="card-tools">
                      <a href="novo-dispositivo.php?project_id=<?php echo $project_id_from_url; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> Novo Dispositivo
                      </a>
                    </div>
                  </div>
                  <div class="card-body table-responsive p-0">
                    <table class="table table-hover">
                      <thead><tr><th>Nome</th><th>Criado por</th><th>ID</th><th>Ações</th></tr></thead>
                      <tbody id="sensores-tbody">
                        <tr><td colspan="4" style="text-align: center;">Carregando dispositivos...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="tab-pane fade" id="tab-participantes" role="tabpanel">
                 <div class="row">
                  <div class="col-md-8">
                    <div class="card card-info">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-users mr-2"></i>Membros do Projeto</h3>
                      </div>
                      <div class="card-body p-0">
                        <ul class="list-group list-group-flush" id="lista-participantes">
                          <li class="list-group-item">Carregando...</li>
                        </ul>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="card card-success">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user-plus mr-2"></i>Convidar Membro</h3>
                      </div>
                      <div class="card-body">
                        <div class="form-group">
                          <label>Email do usuário:</label>
                          <input type="email" id="invite-email" class="form-control" placeholder="usuario@example.com">
                        </div>
                        <div class="form-group">
                          <label>Permissão:</label>
                          <select id="invite-role" class="form-control">
                            <option value="2" selected>Participante</option>
                            <option value="1">Gerente</option>
                          </select>
                        </div>
                        <button class="btn btn-success btn-block" id="btn-convidar">
                          <i class="fas fa-paper-plane mr-2"></i>Enviar Convite
                        </button>
                        <div id="invite-status" class="mt-2"></div>
                      </div>
                    </div>
                    
                    <div class="card card-danger mt-3">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-sign-out-alt mr-2"></i>Sair do Projeto</h3>
                      </div>
                      <div class="card-body">
                        <p class="text-muted">Se você é o único gerente, deverá promover outro participante a gerente antes de sair.</p>
                        <button class="btn btn-danger btn-block" id="btn-sair-projeto">
                          <i class="fas fa-sign-out-alt mr-2"></i>Sair do Projeto
                        </button>
                        <div id="sair-status" class="mt-2"></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              
              <div class="tab-pane fade" id="tab-solicitacoes" role="tabpanel">
                <div class="row">
                  <div class="col-md-12">
                    <div class="card card-info">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-hand-paper mr-2"></i>Solicitações de Participação</h3>
                      </div>
                      <div class="card-body">
                        <div class="btn-group mb-3" role="group">
                          <button type="button" class="btn btn-outline-primary btn-filter-solicitacoes" data-status="pendente">
                            <i class="fas fa-hourglass-half mr-2"></i>Pendentes
                            <span class="badge badge-primary" id="count-pendentes">0</span>
                          </button>
                          <button type="button" class="btn btn-outline-success btn-filter-solicitacoes" data-status="aceito">
                            <i class="fas fa-check mr-2"></i>Aceitas
                            <span class="badge badge-success" id="count-aceitos">0</span>
                          </button>
                          <button type="button" class="btn btn-outline-danger btn-filter-solicitacoes" data-status="rejeitado">
                            <i class="fas fa-times mr-2"></i>Rejeitadas
                            <span class="badge badge-danger" id="count-rejeitados">0</span>
                          </button>
                          <button type="button" class="btn btn-outline-secondary btn-filter-solicitacoes" data-status="todos">
                            <i class="fas fa-list mr-2"></i>Todas
                          </button>
                        </div>
                        <div id="solicitacoes-list" style="margin-top: 20px;">
                          <div class="alert alert-info">Carregando solicitações...</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              
              <div class="tab-pane fade" id="tab-config" role="tabpanel">
                 <div class="row">
                  <div class="col-md-6">
                    <div class="card card-primary">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Informações Gerais</h3>
                      </div>
                      <form class="form-horizontal">
                        <div class="card-body">
                          <div class="form-group row">
                            <label class="col-sm-3 col-form-label">Nome</label>
                            <div class="col-sm-9">
                              <input type="text" class="form-control" id="config-proj-name" value="Carregando...">
                            </div>
                          </div>
                          <div class="form-group row">
                            <label class="col-sm-3 col-form-label">Descrição</label>
                            <div class="col-sm-9">
                              <textarea class="form-control" rows="3" id="config-proj-desc">Carregando...</textarea>
                            </div>
                          </div>
                          <div class="form-group row">
                            <label class="col-sm-3 col-form-label">Tags</label>
                            <div class="col-sm-9">
                              <input type="text" class="form-control" id="config-proj-tags" placeholder="Ex: temperatura, umidade, sensor">
                            </div>
                          </div>
                        </div>
                        <div class="card-footer">
                          <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Salvar Alterações
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="card card-success">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-globe mr-2"></i>Visibilidade do Projeto</h3>
                      </div>
                      <div class="card-body">
                        <p>Controle quem pode visualizar este projeto:</p>
                        <div class="form-group">
                          <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="toggle-project-visibility">
                            <label class="custom-control-label" for="toggle-project-visibility">
                              <span id="visibility-status">Carregando...</span>
                            </label>
                          </div>
                        </div>
                        <small class="text-muted d-block">
                          <i class="fas fa-info-circle mr-1"></i>
                          Quando <strong>público</strong>, qualquer pessoa pode ver e acessar este projeto.
                        </small>
                        <div id="visibility-status-msg" style="margin-top: 10px;"></div>
                      </div>
                    </div>
                  </div>
                </div>
                
                <div class="row">
                  <div class="col-md-6">
                    <div class="card card-warning">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-download mr-2"></i>Exportar Dados</h3>
                      </div>
                      <div class="card-body">
                        <p>Baixe todos os dados do seu projeto em diferentes formatos:</p>
                        <button class="btn btn-outline-primary btn-block mb-2" id="btn-export-csv">
                          <i class="fas fa-file-csv mr-2"></i>Exportar como CSV
                        </button>
                        <button class="btn btn-outline-info btn-block mb-2" id="btn-export-json">
                          <i class="fas fa-file-json mr-2"></i>Exportar como JSON
                        </button>
                        <button class="btn btn-outline-success btn-block" id="btn-export-excel">
                          <i class="fas fa-file-excel mr-2"></i>Exportar como Excel
                        </button>
                        <div id="export-status" class="mt-3"></div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="col-md-6">
                    <div class="card card-danger">
                      <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Zona de Perigo</h3>
                      </div>
                      <div class="card-body">
                        <h5>Excluir Projeto</h5>
                        <p>Esta ação <strong>não pode ser desfeita</strong>. Todos os dados serão perdidos permanentemente.</p>
                        <button class="btn btn-danger" id="btn-deletar-projeto">
                          <i class="fas fa-trash mr-2"></i>Excluir este Projeto
                        </button>
                        <div id="delete-status" class="mt-2"></div>
                      </div>
                    </div>
                  </div>
                </div>
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

<script>
    const PROJECT_ID = <?php echo $project_id_from_url; ?>;
</script>
<script src="../assets/js/fetch-helpers.js"></script>
<script src="../assets/js/profile-picture-helper.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>

<script>
  $(function () {
    
    // --- URLs das APIs ---
    const API_PROJETOS = 'listar_projetos.php';
    const API_DEVICES = 'listar_devices.php';
    const API_PARTICIPANTES_COUNT = `../api/obter_contagem_participantes.php?project_id=${PROJECT_ID}`;
    const API_STATS_PAYLOADS = `../api/obter_stats_payloads.php?project_id=${PROJECT_ID}`;
    const API_GRAFICO_DADOS = 'obter_dados_grafico.php';
    const API_LISTAR_GRAFICOS = `listar_graficos.php?project_id=${PROJECT_ID}`;
    const API_OBTER_CHAVES = 'obter_chaves_dispositivo.php';
    const API_LISTAR_MEMBROS = `../api/listar_participantes.php?project_id=${PROJECT_ID}`;
    const API_DELETAR_GRAFICO = 'deletar_grafico.php';
    const API_SALVAR_GRAFICO_FORM = '../api/salvar_grafico_avancado.php';
    const API_OBTER_DADOS_GRAFICO = '../api/obter_dados_grafico_renderizado.php';
    const API_ALTERAR_VISIBILIDADE_GRAFICO = '../api/alterar_visibilidade_grafico.php';
    const API_ALTERAR_VISIBILIDADE_PROJETO = '../api/alterar_visibilidade_projeto.php';
    const API_DEVICES_FORM = 'listar_devices.php';
    const API_ENVIAR_CONVITE = '../api/enviar_convite.php';
    const API_DELETAR_PROJETO = '../api/deletar_projeto.php';
    const API_EXPORTAR_DADOS = '../api/exportar_dados_projeto.php';
    const API_LISTAR_SOLICITACOES = `../api/listar_solicitacoes_participacao.php?project_id=${PROJECT_ID}`;
    const API_RESPONDER_SOLICITACAO = '../api/responder_solicitacao_participacao.php';
    const API_SAIR_PROJETO = '../api/sair_projeto.php';
    const API_EXPULSAR_PARTICIPANTE = '../api/expulsar_participante.php';
    const API_PROMOVER_GERENTE = '../api/promover_gerente.php';
    
    // --- Referências DOM (Principais) ---
    const projectTitleEl = document.getElementById('project-title');
    const sensoresTbody = document.getElementById('sensores-tbody');
    const configNameEl = document.getElementById('config-proj-name');
    const configDescEl = document.getElementById('config-proj-desc');
    const infoBoxDevices = document.getElementById('info-box-devices');
    const infoBoxParticipantes = document.getElementById('info-box-participantes');
    const infoBoxGraficos = document.getElementById('info-box-graficos');
    const infoBoxUltimaLeitura = document.getElementById('info-box-ultima-leitura');
    const projectTagsContainer = document.getElementById('project-tags-container');
    const geralProjName = document.getElementById('geral-proj-name');
    const geralProjDesc = document.getElementById('geral-proj-desc');
    const geralTagsContainer = document.getElementById('geral-tags-container');
    
    // --- Referências DOM (Visibilidade do Projeto) ---
    const toggleProjectVisibility = document.getElementById('toggle-project-visibility');
    const visibilityStatus = document.getElementById('visibility-status');
    const visibilityStatusMsg = document.getElementById('visibility-status-msg');
    
    // --- Referências DOM (Aba Gráficos) ---
    const graficosSalvosContainer = document.getElementById('graficos-salvos-container');
    const graficosSalvosStatus = document.getElementById('graficos-salvos-status');
    let cacheGraficosSalvos = [];

    // --- Referências DOM (Aba Participantes) ---
    const listaParticipantesUl = document.getElementById('lista-participantes');
    const inviteEmailInput = document.getElementById('invite-email');
    const inviteRoleSelect = document.getElementById('invite-role');
    const inviteButton = document.getElementById('btn-convidar');
    const inviteStatusEl = document.getElementById('invite-status');
    
    // --- Referências DOM (Delete Project) ---
    const deleteProjectButton = document.getElementById('btn-deletar-projeto');
    const deleteStatusEl = document.getElementById('delete-status');

    // --- Variáveis globais ---
    let modoModal = 'criar';
    let idGraficoEdicao = null;
    let projectDevicesList = [];
    let datasets = [];
    let selectedTimeRange = 'all';
    let chartPreview = null;

    // --- FUNÇÃO AUXILIAR: Converter resposta JSON com tratamento de erro ---
    async function safeJson(response) {
        if (!response.ok) {
            const error = await response.json().catch(() => ({ error: `HTTP ${response.status}` }));
            throw new Error(error.error || 'Erro na requisição');
        }
        return response.json();
    }

    // --- FUNÇÃO 2: Carrega Dados Principais (Projeto, Dispositivos) ---
    async function carregarDadosPrincipais() {
        try {
            // Obter informações do projeto específico
            const responseProject = await fetch(`obter_projeto.php?id=${PROJECT_ID}`, { credentials: 'include' });
            const project = await safeJson(responseProject);

            projectTitleEl.textContent = project.name;
            geralProjName.textContent = project.name;
            configNameEl.value = project.name;
            configDescEl.value = project.description || '';
            geralProjDesc.textContent = project.description || '(Sem descrição)';

            if (project.project_tags) {
                const tagsArray = project.project_tags.split(',');
                let tagsHtml = '';
                tagsArray.forEach(tag => {
                    const cores = ['badge-primary', 'badge-info', 'badge-success', 'badge-warning', 'badge-danger'];
                    const cor = cores[Math.floor(Math.random() * cores.length)];
                    tagsHtml += `<span class="badge ${cor}">${tag}</span> `;
                });
                projectTagsContainer.innerHTML = tagsHtml;
                geralTagsContainer.innerHTML = tagsHtml;
            } else {
                geralTagsContainer.innerHTML = '<span class="text-muted">(Sem tags)</span>';
            }

            // Carregar visibilidade do projeto
            if (project.public) {
                toggleProjectVisibility.checked = true;
                visibilityStatus.textContent = '🌐 Projeto Público';
            } else {
                toggleProjectVisibility.checked = false;
                visibilityStatus.textContent = '🔒 Projeto Privado';
            }

            // Obter dispositivos do projeto
            const responseDevices = await fetch(API_DEVICES, { credentials: 'include' });
            const allDevices = await safeJson(responseDevices);

            projectDevicesList = allDevices.filter(d => d.project_id == PROJECT_ID);
            
            infoBoxDevices.textContent = projectDevicesList.length;

            sensoresTbody.innerHTML = ''; 
            
            if (projectDevicesList.length === 0) {
                sensoresTbody.innerHTML = '<tr><td colspan="4" style="text-align: center;">Nenhum dispositivo cadastrado.</td></tr>';
            } else {
                projectDevicesList.forEach(device => {
                    const row = sensoresTbody.insertRow();
                    row.innerHTML = `
                        <td>${device.name}</td>
                        <td>${device.user_username}</td>
                        <td><code>${device.id}</code></td>
                        <td>
                            <a href="gerenciar-dispositivo.php?id=${device.id}&project_id=${PROJECT_ID}" class="btn btn-sm btn-info"><i class="fas fa-cog mr-1"></i>Gerenciar</a> 
                            <a href="#" class="btn btn-sm btn-danger"><i class="fas fa-trash mr-1"></i>Remover</a>
                        </td>
                    `;
                });
            }
        } catch (error) {
            console.error(error);
            projectTitleEl.textContent = "Erro ao carregar projeto: " + error.message;
        }
    }
    
    // --- FUNÇÃO 3: Carrega Stats (Info-Boxes) ---
    async function carregarStats() {
        try {
        const [respParticipantes, respStats] = await Promise.all([
          fetch(API_PARTICIPANTES_COUNT, { credentials: 'include' }),
          fetch(API_STATS_PAYLOADS, { credentials: 'include' })
        ]);

        // Participantes
        if (!respParticipantes.ok) {
          // tenta extrair mensagem e mostrar 'Erro'
          try {
            const err = await respParticipantes.json();
            console.warn('Erro ao buscar participantes:', err);
          } catch (e) {
            const txt = await respParticipantes.text();
            console.warn('Erro ao buscar participantes, resposta não-JSON:', txt.substring(0,200));
          }
          infoBoxParticipantes.textContent = "Erro";
        } else {
          // valida content-type antes de parsear
          const ct = respParticipantes.headers.get('content-type') || '';
          if (ct.indexOf('application/json') !== -1) {
            const dataParticipantes = await respParticipantes.json();
            infoBoxParticipantes.textContent = dataParticipantes.error ? "Erro" : dataParticipantes.participant_count;
          } else {
            infoBoxParticipantes.textContent = "Erro";
            console.warn('Resposta participantes não é JSON:', await respParticipantes.text());
          }
        }

        // Stats payloads
        if (!respStats.ok) {
          try {
            const err = await respStats.json();
            console.warn('Erro ao buscar stats:', err);
          } catch (e) {
            const txt = await respStats.text();
            console.warn('Erro ao buscar stats, resposta não-JSON:', txt.substring(0,200));
          }
          infoBoxUltimaLeitura.textContent = "Erro";
        } else {
          const ct2 = respStats.headers.get('content-type') || '';
          if (ct2.indexOf('application/json') !== -1) {
            const dataStats = await respStats.json();
            if (dataStats.error) {
              infoBoxUltimaLeitura.textContent = "Erro";
            } else {
              infoBoxUltimaLeitura.textContent = (dataStats.ultima_leitura === "Nenhuma") ? "Nenhuma" : new Date(dataStats.ultima_leitura).toLocaleString('pt-BR');
            }
          } else {
            infoBoxUltimaLeitura.textContent = "Erro";
            console.warn('Resposta stats não é JSON:', await respStats.text());
          }
        }
        } catch (error) { console.error("Erro ao buscar stats:", error); }
    }

    // --- FUNÇÃO 4: Carrega Gráficos Salvos (Aba "Visão Geral") ---
    async function carregarGraficosSalvos() {
        graficosSalvosStatus.innerHTML = 'Carregando...';
        graficosSalvosContainer.innerHTML = '';
        
        try {
            const response = await fetch(API_LISTAR_GRAFICOS, { credentials: 'include' });
            const graficos = await safeJson(response);
            
            cacheGraficosSalvos = graficos;
            infoBoxGraficos.textContent = graficos.length;
            
            if (graficos.length === 0) {
                graficosSalvosStatus.innerHTML = 'Nenhum gráfico salvo. Crie um na aba <strong>Gráficos</strong>!';
                return;
            }
            
            graficosSalvosStatus.style.display = 'none';
            
            graficos.forEach((grafico, index) => {
                const canvasId = `canvas-grafico-${grafico.id}`;
                
                // Criar card
                const card = document.createElement('div');
                card.className = 'card';
                card.style.display = 'flex';
                card.style.flexDirection = 'column';
                card.style.height = '100%';
                card.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                
                // Header do card
                const header = document.createElement('div');
                header.className = 'card-header';
                header.style.backgroundColor = '#f8f9fa';
                header.style.padding = '10px 15px';
                header.style.borderBottom = '1px solid #dee2e6';
                
                const title = document.createElement('h5');
                title.className = 'mb-0';
                title.style.marginBottom = '0';
                title.innerHTML = `
                    <i class="fas fa-chart-${grafico.chart_type === 'pie' ? 'pie' : 'line'} mr-2"></i>
                    <strong>${escapeHtml(grafico.name)}</strong>
                    <span class="badge ${grafico.is_public ? 'badge-success' : 'badge-secondary'}" style="float: right;">
                        ${grafico.is_public ? '🌐 Público' : '🔒 Privado'}
                    </span>
                `;
                header.appendChild(title);
                
                // Body do card com canvas
                const body = document.createElement('div');
                body.className = 'card-body';
                body.style.padding = '10px';
                body.style.flex = '1';
                
                const canvas = document.createElement('canvas');
                canvas.id = canvasId;
                canvas.style.minHeight = '250px';
                canvas.style.maxHeight = '300px';
                body.appendChild(canvas);
                
                // Footer do card com botões
                const footer = document.createElement('div');
                footer.className = 'card-footer';
                footer.style.backgroundColor = '#f8f9fa';
                footer.style.display = 'flex';
                footer.style.justifyContent = 'space-between';
                footer.style.alignItems = 'center';
                footer.style.padding = '10px';
                
                // Grupo de ferramentas (esquerda)
                const toolsGroup = document.createElement('div');
                toolsGroup.style.display = 'flex';
                toolsGroup.style.gap = '5px';
                
                const btnDownload = document.createElement('button');
                btnDownload.className = 'btn btn-sm btn-outline-primary';
                btnDownload.title = 'Baixar como imagem';
                btnDownload.innerHTML = '<i class="fas fa-download"></i>';
                btnDownload.addEventListener('click', () => downloadChartImage(canvasId, grafico.name));
                
                const btnResetZoom = document.createElement('button');
                btnResetZoom.className = 'btn btn-sm btn-outline-secondary';
                btnResetZoom.title = 'Resetar zoom';
                btnResetZoom.innerHTML = '<i class="fas fa-search-minus"></i>';
                btnResetZoom.addEventListener('click', () => resetChartZoom(canvasId));
                
                const btnFullscreen = document.createElement('button');
                btnFullscreen.className = 'btn btn-sm btn-outline-secondary';
                btnFullscreen.title = 'Tela cheia';
                btnFullscreen.innerHTML = '<i class="fas fa-expand"></i>';
                btnFullscreen.addEventListener('click', () => toggleFullscreen(card));
                
                toolsGroup.appendChild(btnDownload);
                toolsGroup.appendChild(btnResetZoom);
                toolsGroup.appendChild(btnFullscreen);
                
                // Grupo de ações (direita)
                const actionsGroup = document.createElement('div');
                actionsGroup.style.display = 'flex';
                actionsGroup.style.gap = '5px';
                
                const btnToggle = document.createElement('button');
                btnToggle.className = 'btn btn-sm btn-outline-info';
                btnToggle.title = 'Alternar visibilidade';
                btnToggle.innerHTML = `<i class="fas fa-${grafico.is_public ? 'globe' : 'lock'}"></i>`;
                btnToggle.addEventListener('click', () => alternarVisibilidadeGrafico(grafico));
                
                const btnDelete = document.createElement('button');
                btnDelete.className = 'btn btn-sm btn-outline-danger';
                btnDelete.title = 'Apagar gráfico';
                btnDelete.innerHTML = '<i class="fas fa-trash"></i>';
                btnDelete.addEventListener('click', () => apagarGrafico(grafico));
                
                actionsGroup.appendChild(btnToggle);
                actionsGroup.appendChild(btnDelete);
                
                footer.appendChild(toolsGroup);
                footer.appendChild(actionsGroup);
                
                // Montar card
                card.appendChild(header);
                card.appendChild(body);
                card.appendChild(footer);
                
                graficosSalvosContainer.appendChild(card);
                
                // Renderizar gráfico no canvas
                setTimeout(() => {
                    renderizarGraficoNoCanvas(grafico, canvasId);
                }, 100 * index);
            });
            
        } catch (error) {
            console.error(error);
            graficosSalvosStatus.innerHTML = `<span style="color: red;">${error.message}</span>`;
        }
    }
    
    // ===== ESCAPE HTML =====
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // --- FUNÇÃO 5: Carrega a lista de participantes ---
    async function carregarParticipantes() {
        listaParticipantesUl.innerHTML = '<li class="list-group-item">Carregando...</li>';
        try {
            const response = await fetch(API_LISTAR_MEMBROS, { credentials: 'include' });
            const membros = await safeJson(response);
            
            listaParticipantesUl.innerHTML = '';
            if (membros.length === 0) {
                listaParticipantesUl.innerHTML = '<li class="list-group-item">Nenhum membro encontrado.</li>';
                return;
            }
            
            let usuarioEhGerente = false;
            const usuarioLogadoId = document.querySelector('[data-user-id]')?.dataset.userId;
            
            membros.forEach(membro => {
                // Verificar se o usuário logado é gerente
                if (membro.role_name === 'Gerente') {
                    // Pode ser que precisemos fazer uma requisição para GET o user_id do logado
                }
                
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center';
                const isGerente = membro.role_name === 'Gerente';
                const roleClass = isGerente ? 'text-success' : 'text-muted';
                const iniciais = membro.user_name.split(' ').map(n => n[0]).join('').substring(0,2).toUpperCase();
                
                // Usar foto real se disponível, senão usar placeholder com iniciais
                let avatar;
                if (membro.profile_picture) {
                    avatar = `<img src="../../${escapeHtml(membro.profile_picture)}" alt="${escapeHtml(membro.user_name)}" class="img-circle img-size-50 mr-3" style="object-fit: cover;">`;
                } else {
                    avatar = `<img src="https://placehold.co/128x128/007BFF/FFFFFF?text=${iniciais}" alt="${escapeHtml(membro.user_name)}" class="img-circle img-size-50 mr-3">`;
                }
                
                // Gerar menu de ações
                let acoes = '';
                if (!isGerente) {
                    acoes = `
                        <div class="btn-group" role="group">
                            <button class="btn btn-sm btn-outline-warning btn-promover-gerente" data-user-id="${membro.user_id}" title="Promover a Gerente">
                                <i class="fas fa-crown mr-1"></i>Promover
                            </button>
                            <button class="btn btn-sm btn-outline-danger btn-expulsar-participante" data-user-id="${membro.user_id}" title="Expulsar do projeto">
                                <i class="fas fa-times mr-1"></i>Expulsar
                            </button>
                        </div>
                    `;
                }
                
                li.innerHTML = `
                    <div class="d-flex align-items-center">
                        ${avatar}
                        <div>
                            <strong>${membro.user_name}</strong> (@${membro.user_username})
                            <small class="d-block ${roleClass}">${membro.role_name}</small>
                        </div>
                    </div>
                    ${acoes}
                `;
                li.dataset.userId = membro.user_id;
                listaParticipantesUl.appendChild(li);
            });
            
            // Adicionar event listeners nos botões
            document.querySelectorAll('.btn-promover-gerente').forEach(btn => {
                btn.addEventListener('click', function() {
                    promoverGerente(this.dataset.userId);
                });
            });
            
            document.querySelectorAll('.btn-expulsar-participante').forEach(btn => {
                btn.addEventListener('click', function() {
                    expulsarParticipante(this.dataset.userId);
                });
            });
            
        } catch (error) {
            listaParticipantesUl.innerHTML = `<li class="list-group-item text-danger">${error.message}</li>`;
        }
    }
    
    // Promover Participante a Gerente
    async function promoverGerente(userId) {
        if (!confirm('Deseja promover este participante a Gerente?')) return;
        
        try {
            const response = await fetch(API_PROMOVER_GERENTE, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    project_id: PROJECT_ID,
                    user_id: parseInt(userId)
                })
            });

            const result = await safeJson(response);

            if (result.success) {
                alert(result.message);
                carregarParticipantes();
            } else {
                alert('Erro: ' + (result.error || result.message));
            }

        } catch (error) {
            alert('Erro ao promover participante: ' + error.message);
        }
    }
    
    // Expulsar Participante
    async function expulsarParticipante(userId) {
        if (!confirm('Deseja expulsar este participante do projeto?')) return;
        
        try {
            const response = await fetch(API_EXPULSAR_PARTICIPANTE, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    project_id: PROJECT_ID,
                    user_id: parseInt(userId)
                })
            });

            const result = await safeJson(response);

            if (result.success) {
                alert(result.message);
                carregarParticipantes();
            } else {
                alert('Erro: ' + (result.error || result.message));
            }

        } catch (error) {
            alert('Erro ao expulsar participante: ' + error.message);
        }
    }
    
    // Sair do Projeto
    async function sairProjeto() {
        const sairStatus = document.getElementById('sair-status');
        const btnSair = document.getElementById('btn-sair-projeto');
        
        if (!confirm('⚠️ Tem certeza que deseja sair deste projeto?')) {
            return;
        }
        
        btnSair.disabled = true;
        btnSair.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saindo...';
        sairStatus.innerHTML = '';
        
        try {
            const response = await fetch(API_SAIR_PROJETO, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    project_id: PROJECT_ID
                })
            });

            const result = await safeJson(response);

            if (result.success) {
                sairStatus.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i>' + result.message + '</div>';
                setTimeout(() => {
                    window.location.href = 'meus-projetos.php';
                }, 2000);
            } else {
                sairStatus.innerHTML = `<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>${result.error || result.message}</div>`;
                btnSair.disabled = false;
                btnSair.innerHTML = '<i class="fas fa-sign-out-alt mr-2"></i>Sair do Projeto';
            }

        } catch (error) {
            sairStatus.innerHTML = `<div class="alert alert-danger"><i class="fas fa-times-circle mr-2"></i>Erro: ${error.message}</div>`;
            btnSair.disabled = false;
            btnSair.innerHTML = '<i class="fas fa-sign-out-alt mr-2"></i>Sair do Projeto';
        }
    }

      // --- FUNÇÃO 5.5: Carregar e Gerenciar Solicitações de Participação ---
      let currentSolicitacoesFilter = 'pendente';
      
      async function carregarSolicitacoes(status = 'pendente') {
        currentSolicitacoesFilter = status;
        const listEl = document.getElementById('solicitacoes-list');
        listEl.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin mr-2"></i>Carregando solicitações...</div>';

        try {
            const apiUrl = `${API_LISTAR_SOLICITACOES}&status=${status}`;
            const response = await fetch(apiUrl, { credentials: 'include' });
            const result = await safeJson(response);

            if (!result.success || result.requests.length === 0) {
                listEl.innerHTML = '<div class="alert alert-info">Nenhuma solicitação encontrada.</div>';
                return;
            }

            // Atualizar contadores
            document.getElementById('count-pendentes').textContent = result.requests.filter(r => r.status === 'pendente').length;
            document.getElementById('count-aceitos').textContent = result.requests.filter(r => r.status === 'aceito').length;
            document.getElementById('count-rejeitados').textContent = result.requests.filter(r => r.status === 'rejeitado').length;

            // Renderizar solicitações
            let html = '';
            result.requests.forEach(req => {
                const statusBadge = {
                    'pendente': '<span class="badge badge-warning"><i class="fas fa-hourglass-half mr-1"></i>Pendente</span>',
                    'aceito': '<span class="badge badge-success"><i class="fas fa-check mr-1"></i>Aceito</span>',
                    'rejeitado': '<span class="badge badge-danger"><i class="fas fa-times mr-1"></i>Rejeitado</span>'
                }[req.status];

                const dataFormatada = new Date(req.createdAt).toLocaleDateString('pt-BR');
                const acoes = req.status === 'pendente' ? `
                    <button class="btn btn-sm btn-success mr-2 btn-aceitar-solicitacao" data-id="${req.id}">
                        <i class="fas fa-check mr-1"></i>Aceitar
                    </button>
                    <button class="btn btn-sm btn-danger btn-rejeitar-solicitacao" data-id="${req.id}">
                        <i class="fas fa-times mr-1"></i>Recusar
                    </button>
                ` : '';

                html += `
                    <div class="card mb-2">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6 class="card-title mb-1">${escapeHtml(req.user_name)} (@${escapeHtml(req.user_username)})</h6>
                                    <p class="text-muted mb-1"><small><i class="fas fa-envelope mr-1"></i>${escapeHtml(req.user_email)}</small></p>
                                    <p class="text-muted mb-1"><small><i class="fas fa-comment mr-1"></i>${escapeHtml(req.message || 'Sem mensagem')}</small></p>
                                    <p class="text-muted"><small><i class="fas fa-calendar mr-1"></i>Solicitado em ${dataFormatada}</small></p>
                                </div>
                                <div class="col-md-4" style="display: flex; flex-direction: column; justify-content: space-between;">
                                    <div>${statusBadge}</div>
                                    <div>
                                        ${acoes}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            listEl.innerHTML = html;

            // Adicionar event listeners
            document.querySelectorAll('.btn-aceitar-solicitacao').forEach(btn => {
                btn.addEventListener('click', function() {
                    responderSolicitacao(this.dataset.id, 'aceitar');
                });
            });

            document.querySelectorAll('.btn-rejeitar-solicitacao').forEach(btn => {
                btn.addEventListener('click', function() {
                    responderSolicitacao(this.dataset.id, 'recusar');
                });
            });

        } catch (error) {
            listEl.innerHTML = `<div class="alert alert-danger"><i class="fas fa-times-circle mr-2"></i>Erro: ${error.message}</div>`;
        }
      }

      async function responderSolicitacao(requestId, action) {
        const actionText = action === 'aceitar' ? 'Aceitar' : 'Recusar';
        
        try {
            const response = await fetch(API_RESPONDER_SOLICITACAO, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    request_id: parseInt(requestId),
                    action: action
                })
            });

            const result = await safeJson(response);

            if (result.success) {
                alert(result.message);
                // Recarregar lista
                carregarSolicitacoes(currentSolicitacoesFilter);
            } else {
                alert('Erro: ' + (result.error || result.message));
            }

        } catch (error) {
            alert('Erro ao responder solicitação: ' + error.message);
        }
      }

      // --- FUNÇÃO 6: Alterar Visibilidade do Projeto ---
      async function alterarVisibilidadeProjeto(isPublic) {
        visibilityStatusMsg.innerHTML = '<div class="alert alert-info mb-0"><i class="fas fa-spinner fa-spin mr-2"></i>Alterando visibilidade...</div>';
        toggleProjectVisibility.disabled = true;

        try {
            const response = await fetch(API_ALTERAR_VISIBILIDADE_PROJETO, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    project_id: PROJECT_ID,
                    public: isPublic ? 1 : 0
                })
            });

            const result = await safeJson(response);
            
            // Sucesso
            const mensagem = isPublic ? '✓ Projeto tornado público!' : '✓ Projeto tornado privado!';
            visibilityStatus.textContent = isPublic ? '🌐 Projeto Público' : '🔒 Projeto Privado';
            visibilityStatusMsg.innerHTML = `<div class="alert alert-success mb-0"><i class="fas fa-check-circle mr-2"></i>${mensagem}</div>`;
            
            setTimeout(() => {
                visibilityStatusMsg.innerHTML = '';
            }, 3000);

        } catch (error) {
            // Erro
            toggleProjectVisibility.checked = !isPublic;
            visibilityStatusMsg.innerHTML = `<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-circle mr-2"></i>Erro: ${error.message}</div>`;
        } finally {
            toggleProjectVisibility.disabled = false;
        }
      }

      // --- FUNÇÃO 7: Deletar Projeto ---
      async function deletarProjeto() {
        // Confirmação do usuário
        if (!confirm('⚠️ Tem certeza que deseja EXCLUIR este projeto?\n\nEsta ação irá:\n• Remover o projeto permanentemente\n• Apagar todos os dados associados\n• Esta ação NÃO pode ser desfeita!\n\nDeseja continuar?')) {
            return;
        }

        // Desabilita botão e mostra loading
        deleteProjectButton.disabled = true;
        deleteProjectButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Excluindo projeto...';
        deleteStatusEl.innerHTML = '';

        try {
            const response = await fetch(API_DELETAR_PROJETO, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ project_id: PROJECT_ID })
            });

            const result = await safeJson(response);
            
            // Sucesso - exibe mensagem e redireciona
            deleteStatusEl.innerHTML = '<div class="alert alert-success mb-0"><i class="fas fa-check-circle mr-2"></i>Projeto excluído com sucesso! Redirecionando...</div>';
            
            setTimeout(() => {
                window.location.href = 'meus-projetos.php';
            }, 2000);

        } catch (error) {
            // Erro - exibe mensagem
            deleteStatusEl.innerHTML = `<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-circle mr-2"></i>${error.message}</div>`;
            
            // Reativa botão
            deleteProjectButton.disabled = false;
            deleteProjectButton.innerHTML = '<i class="fas fa-trash mr-2"></i>Excluir este Projeto';
        }
      }

      // --- FUNÇÃO 7: Enviar Convite ---
      async function enviarConvite() {
        const email = inviteEmailInput.value.trim();
        const roleId = parseInt(inviteRoleSelect.value, 10);

        inviteStatusEl.innerHTML = '';

        if (!email) {
          inviteStatusEl.innerHTML = '<div class="alert alert-warning mb-0">Informe um email válido.</div>';
          return;
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
          inviteStatusEl.innerHTML = '<div class="alert alert-warning mb-0">Email inválido.</div>';
          return;
        }

        if (!roleId || Number.isNaN(roleId)) {
          inviteStatusEl.innerHTML = '<div class="alert alert-warning mb-0">Selecione uma permissao.</div>';
          return;
        }

        inviteButton.disabled = true;
        inviteButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enviando...';

        try {
          const response = await fetch(API_ENVIAR_CONVITE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
              project_id: PROJECT_ID,
              invited_email: email,
              role_id: roleId
            })
          });

          const result = await safeJson(response);
          inviteStatusEl.innerHTML = `<div class="alert alert-success mb-0">${result.message || 'Convite enviado!'}</div>`;
          inviteEmailInput.value = '';
        } catch (error) {
          inviteStatusEl.innerHTML = `<div class="alert alert-danger mb-0">${error.message}</div>`;
        } finally {
          inviteButton.disabled = false;
          inviteButton.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>Enviar Convite';
        }
      }
    
    // --- FERRAMENTAS DE GRÁFICO ---
    
    // Download do gráfico como imagem
    function downloadChartImage(canvasId, chartName) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;
      
      const link = document.createElement('a');
      link.download = `${chartName.replace(/\s+/g, '_')}_${new Date().getTime()}.png`;
      link.href = canvas.toDataURL('image/png');
      link.click();
    }
    
    // Reset do zoom do gráfico
    function resetChartZoom(canvasId) {
      const chart = window[`chart_${canvasId}`];
      if (chart && chart.resetZoom) {
        chart.resetZoom();
      }
    }
    
    // Toggle fullscreen
    function toggleFullscreen(element) {
      if (!element) return;
      
      if (!document.fullscreenElement) {
        if (element.requestFullscreen) {
          element.requestFullscreen();
        } else if (element.mozRequestFullScreen) {
          element.mozRequestFullScreen();
        } else if (element.webkitRequestFullscreen) {
          element.webkitRequestFullscreen();
        } else if (element.msRequestFullscreen) {
          element.msRequestFullscreen();
        }
      } else {
        if (document.exitFullscreen) {
          document.exitFullscreen();
        } else if (document.mozCancelFullScreen) {
          document.mozCancelFullScreen();
        } else if (document.webkitExitFullscreen) {
          document.webkitExitFullscreen();
        } else if (document.msExitFullscreen) {
          document.msExitFullscreen();
        }
      }
    }
    
    // --- EXPORTAR DADOS DO PROJETO ---
    async function exportarDados(formato) {
      const statusEl = document.getElementById('export-status');
      const btnCsv = document.getElementById('btn-export-csv');
      const btnJson = document.getElementById('btn-export-json');
      const btnExcel = document.getElementById('btn-export-excel');
      
      // Desabilitar botões
      btnCsv.disabled = true;
      btnJson.disabled = true;
      btnExcel.disabled = true;
      
      statusEl.innerHTML = '<div class="alert alert-info mb-0"><i class="fas fa-spinner fa-spin mr-2"></i>Buscando dados...</div>';
      
      try {
        const response = await fetch(`${API_EXPORTAR_DADOS}?project_id=${PROJECT_ID}`, {
          credentials: 'include'
        });
        
        const result = await safeJson(response);
        
        if (!result.success || !result.data || result.data.length === 0) {
          statusEl.innerHTML = '<div class="alert alert-warning mb-0">Nenhum dado disponível para exportar</div>';
          return;
        }
        
        const projectName = result.project.name.replace(/\s+/g, '_');
        const timestamp = new Date().toISOString().slice(0, 10);
        const filename = `${projectName}_${timestamp}`;
        
        statusEl.innerHTML = `<div class="alert alert-info mb-0"><i class="fas fa-spinner fa-spin mr-2"></i>Gerando arquivo ${formato.toUpperCase()}...</div>`;
        
        switch (formato) {
          case 'csv':
            exportarCSV(result.data, filename);
            break;
          case 'json':
            exportarJSON(result, filename);
            break;
          case 'excel':
            exportarExcel(result.data, filename, result.project.name);
            break;
        }
        
        statusEl.innerHTML = `<div class="alert alert-success mb-0"><i class="fas fa-check mr-2"></i>Arquivo exportado com sucesso! (${result.total_records} registros)</div>`;
        
        setTimeout(() => {
          statusEl.innerHTML = '';
        }, 3000);
        
      } catch (error) {
        console.error('Erro ao exportar:', error);
        statusEl.innerHTML = `<div class="alert alert-danger mb-0"><i class="fas fa-times mr-2"></i>${error.message}</div>`;
      } finally {
        // Reabilitar botões
        btnCsv.disabled = false;
        btnJson.disabled = false;
        btnExcel.disabled = false;
      }
    }
    
    // Exportar como CSV
    function exportarCSV(data, filename) {
      if (!data || data.length === 0) return;
      
      // Coletar todas as chaves únicas
      const headers = new Set();
      data.forEach(row => {
        Object.keys(row).forEach(key => headers.add(key));
      });
      
      const headersArray = Array.from(headers);
      
      // Criar CSV
      let csv = headersArray.join(',') + '\n';
      
      data.forEach(row => {
        const values = headersArray.map(header => {
          let value = row[header] !== undefined ? row[header] : '';
          
          // Escapar valores com vírgula ou aspas
          if (typeof value === 'string' && (value.includes(',') || value.includes('"') || value.includes('\n'))) {
            value = '"' + value.replace(/"/g, '""') + '"';
          }
          
          return value;
        });
        
        csv += values.join(',') + '\n';
      });
      
      // Download
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = filename + '.csv';
      link.click();
      URL.revokeObjectURL(link.href);
    }
    
    // Exportar como JSON
    function exportarJSON(data, filename) {
      const json = JSON.stringify(data, null, 2);
      const blob = new Blob([json], { type: 'application/json' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = filename + '.json';
      link.click();
      URL.revokeObjectURL(link.href);
    }
    
    // Exportar como Excel
    function exportarExcel(data, filename, projectName) {
      if (!data || data.length === 0) return;
      
      // Criar workbook
      const wb = XLSX.utils.book_new();
      
      // Converter dados para worksheet
      const ws = XLSX.utils.json_to_sheet(data);
      
      // Ajustar largura das colunas
      const colWidths = [];
      const headers = Object.keys(data[0]);
      headers.forEach((header, i) => {
        let maxLen = header.length;
        data.forEach(row => {
          const value = String(row[header] || '');
          if (value.length > maxLen) {
            maxLen = value.length;
          }
        });
        colWidths.push({ wch: Math.min(maxLen + 2, 50) });
      });
      ws['!cols'] = colWidths;
      
      // Adicionar worksheet ao workbook
      XLSX.utils.book_append_sheet(wb, ws, 'Dados');
      
      // Adicionar informações do projeto em outra aba
      const info = [
        { Campo: 'Nome do Projeto', Valor: projectName },
        { Campo: 'Data de Exportação', Valor: new Date().toLocaleString('pt-BR') },
        { Campo: 'Total de Registros', Valor: data.length }
      ];
      const wsInfo = XLSX.utils.json_to_sheet(info);
      XLSX.utils.book_append_sheet(wb, wsInfo, 'Informações');
      
      // Download
      XLSX.writeFile(wb, filename + '.xlsx');
    }
    
    // --- RENDERIZAR GRÁFICO NO CANVAS ---
    async function renderizarGraficoNoCanvas(grafico, canvasId) {
      try {
        // Obter dados do gráfico
        const url = `${API_OBTER_DADOS_GRAFICO}?chart_id=${grafico.id}`;
        const response = await fetch(url, { credentials: 'include' });
        
        if (!response.ok) {
          console.warn(`Erro ao buscar dados do gráfico ${grafico.id}`);
          return;
        }
        
        const dados = await response.json();
        const payloads = dados.payloads || [];
        
        if (payloads.length === 0) {
          console.warn(`Nenhum payload para o gráfico ${grafico.id}`);
          return;
        }
        
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        
        // Processar dados para Chart.js
        const chartType = grafico.chart_type;
        const isAreaChart = chartType === 'area';
        
        // Converter tipo se necessário
        const actualChartType = chartType === 'area' ? 'line' : chartType;
        
        const labels = payloads.map(p => {
          const date = new Date(p.timestamp);
          return date.toLocaleDateString('pt-BR');
        });
        
        // Criar datasets
        let chartDatasets = [];
        
        if (grafico.is_advanced && grafico.datasets && grafico.datasets.length > 0) {
          // Gráfico avançado com múltiplos datasets
          grafico.datasets.forEach((dataset, index) => {
            const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'];
            const color = dataset.color || colors[index % colors.length];
            
            // Filtrar payloads para este dataset
            const dsPayloads = payloads.filter(p => 
              p.device_id == dataset.device_id
            );
            
            // Extrair valores JSON
            const values = dsPayloads.map(p => {
              try {
                const json = typeof p.payload === 'string' ? JSON.parse(p.payload) : p.payload;
                return json[dataset.variable_name] || null;
              } catch (e) {
                return null;
              }
            });
            
            const datasetConfig = {
              label: dataset.alias || `${dataset.device_name} - ${dataset.variable_name}`,
              data: values,
              borderColor: color,
              backgroundColor: (actualChartType === 'pie' || actualChartType === 'doughnut') ? color : `${color}33`,
              borderWidth: 2,
              tension: 0.4
            };
            
            // Adicionar fill para gráfico de área
            if (isAreaChart) {
              datasetConfig.fill = true;
            }
            
            chartDatasets.push(datasetConfig);
          });
        } else {
          // Gráfico simples - uma única variável
          const values = payloads.map(p => {
            try {
              const json = typeof p.payload === 'string' ? JSON.parse(p.payload) : p.payload;
              return json[grafico.json_key] || null;
            } catch (e) {
              return null;
            }
          });
          
          const datasetConfig = {
            label: grafico.json_key,
            data: values,
            borderColor: '#36A2EB',
            backgroundColor: '#36A2EB33',
            borderWidth: 2,
            tension: 0.4
          };
          
          // Adicionar fill para gráfico de área
          if (isAreaChart) {
            datasetConfig.fill = true;
          }
          
          chartDatasets.push(datasetConfig);
        }
        
        // Destruir gráfico anterior se existir
        if (window[`chart_${canvasId}`]) {
          window[`chart_${canvasId}`].destroy();
        }
        
        // Criar gráfico
        const ctx = canvas.getContext('2d');
        window[`chart_${canvasId}`] = new Chart(ctx, {
          type: actualChartType,
          data: {
            labels: labels,
            datasets: chartDatasets
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              mode: 'index',
              intersect: false,
            },
            scales: actualChartType === 'pie' || actualChartType === 'doughnut' ? {} : {
              y: {
                beginAtZero: false,
                grid: { 
                  drawBorder: false,
                  color: 'rgba(0,0,0,0.05)'
                },
                ticks: {
                  callback: function(value) {
                    return Number(value).toFixed(2);
                  }
                }
              },
              x: {
                grid: { 
                  drawBorder: false,
                  display: false
                }
              }
            },
            plugins: {
              legend: {
                display: true,
                position: 'top',
                labels: {
                  usePointStyle: true,
                  padding: 15
                },
                onClick: function(e, legendItem, legend) {
                  const index = legendItem.datasetIndex;
                  const ci = legend.chart;
                  const meta = ci.getDatasetMeta(index);
                  meta.hidden = meta.hidden === null ? !ci.data.datasets[index].hidden : null;
                  ci.update();
                }
              },
              title: {
                display: false
              },
              tooltip: {
                enabled: true,
                mode: 'index',
                intersect: false,
                backgroundColor: 'rgba(0,0,0,0.8)',
                titleColor: '#fff',
                bodyColor: '#fff',
                borderColor: 'rgba(255,255,255,0.2)',
                borderWidth: 1,
                padding: 12,
                displayColors: true,
                callbacks: {
                  label: function(context) {
                    let label = context.dataset.label || '';
                    if (label) {
                      label += ': ';
                    }
                    if (context.parsed.y !== null) {
                      label += Number(context.parsed.y).toFixed(2);
                    }
                    return label;
                  }
                }
              },
              zoom: actualChartType === 'pie' || actualChartType === 'doughnut' ? {} : {
                zoom: {
                  wheel: {
                    enabled: true,
                    speed: 0.1
                  },
                  pinch: {
                    enabled: true
                  },
                  mode: 'x',
                },
                pan: {
                  enabled: true,
                  mode: 'x',
                  modifierKey: 'shift'
                },
                limits: {
                  x: {min: 'original', max: 'original'},
                  y: {min: 'original', max: 'original'}
                }
              }
            }
          }
        });
        
      } catch (error) {
        console.error(`Erro ao renderizar gráfico ${grafico.id}:`, error);
      }
    }
    
    // --- ALTERNAR VISIBILIDADE DO GRÁFICO ---
    async function alternarVisibilidadeGrafico(grafico) {
      const novoStatus = grafico.is_public ? 0 : 1;
      const confirmMsg = grafico.is_public 
        ? 'Tem certeza que deseja deixar este gráfico PRIVADO?'
        : 'Tem certeza que deseja deixar este gráfico PÚBLICO?';
      
      if (!confirm(confirmMsg)) {
        return;
      }
      
      try {
        const response = await fetch(API_ALTERAR_VISIBILIDADE_GRAFICO, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ 
            chart_id: grafico.id, 
            is_public: novoStatus 
          })
        });
        
        const result = await safeJson(response);
        
        if (response.ok) {
          grafico.is_public = novoStatus;
          alert(result.message);
          await carregarGraficosSalvos();
        } else {
          alert(`Erro: ${result.error}`);
        }
      } catch (error) {
        alert(`Erro ao alterar visibilidade: ${error.message}`);
      }
    }
    
    // --- FUNÇÕES DE AÇÃO (Gráficos) ---
    async function apagarGrafico(grafico) {
        if (!confirm(`Tem certeza que deseja apagar o gráfico "${grafico.name}"?`)) {
            return;
        }
        
        try {
            const response = await fetch(API_DELETAR_GRAFICO, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ chart_id: grafico.id }),
              credentials: 'include'
            });
            const resultado = await safeJson(response);
            
            alert(resultado.message);
            await carregarGraficosSalvos();
            
        } catch (error) {
            alert(`Erro ao apagar: ${error.message}`);
        }
    }

    // --- ALTERNAR VISIBILIDADE DO GRÁFICO ---
    async function alternarVisibilidadeGrafico(grafico) {
        const novoStatus = grafico.is_public ? 0 : 1;
        const mensagem = novoStatus ? 'Deixar público?' : 'Deixar privado?';
        
        if (!confirm(mensagem)) {
            return;
        }
        
        try {
            const response = await fetch(API_ALTERAR_VISIBILIDADE_GRAFICO, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ chart_id: grafico.id, is_public: novoStatus }),
              credentials: 'include'
            });
            const resultado = await safeJson(response);
            
            grafico.is_public = resultado.is_public;
            await carregarGraficosSalvos();
            
        } catch (error) {
            alert(`Erro ao alterar visibilidade: ${error.message}`);
        }
    }

    // --- CARREGAR DISPOSITIVOS (Formulário) =====
    async function carregarDispositivosFormulario() {
      try {
        const response = await fetch(API_DEVICES_FORM, { credentials: 'include' });
        const devices = await safeJson(response);
        
        let html = '<option value="">-- Escolha um dispositivo --</option>';
        devices.forEach(device => {
          if (device.project_id == PROJECT_ID) {
            html += `<option value="${device.id}">${device.name}</option>`;
          }
        });
        
        $('#select-device').html(html);
        $('.select2').trigger('change');
      } catch (error) {
        console.error('Erro ao carregar dispositivos:', error);
      }
    }
    
    // ===== CARREGAR VARIÁVEIS (Formulário) =====
    async function carregarVariaveisFormulario() {
      const deviceId = $('#select-device').val();
      $('#select-variable').html('<option>-- Carregando --</option>').prop('disabled', true);
      
      if (!deviceId) {
        $('#select-variable').html('<option>Selecione um dispositivo</option>').prop('disabled', true);
        return;
      }
      
      try {
        const response = await fetch(`${API_OBTER_CHAVES}?device_id=${deviceId}`, { credentials: 'include' });
        const variables = await safeJson(response);
        
        let html = '<option value="">-- Escolha uma variável --</option>';
        if (Array.isArray(variables)) {
          variables.forEach(varName => {
            html += `<option value="${varName}">${varName}</option>`;
          });
        }
        
        $('#select-variable').html(html).prop('disabled', false);
      } catch (error) {
        $('#select-variable').html('<option>Erro ao carregar variáveis</option>');
        console.error('Erro:', error);
      }
    }
    
    // ===== SELEÇÃO DE INTERVALO DE TEMPO =====
    function selecionarIntervaloTempo(btn) {
      const range = btn.data('range');
      selectedTimeRange = range;
      
      $('.time-range-btn').removeClass('active');
      btn.addClass('active');
      
      if (range === 'all') {
        $('#custom-dates').hide();
        $('#date-start').val('');
        $('#date-end').val('');
      } else {
        $('#custom-dates').hide();
      }
    }
    
    // ===== ADICIONAR DATASET =====
    function adicionarDataset() {
      const deviceId = $('#select-device').val();
      const variableName = $('#select-variable').val();
      const axis = $('#variable-axis').val();
      const color = $('#variable-color').val();
      
      if (!deviceId || !variableName) {
        alert('Selecione um dispositivo e uma variável');
        return;
      }
      
      const deviceName = $('#select-device option:selected').text();
      
      datasets.push({
        device_id: parseInt(deviceId),
        device_name: deviceName,
        variable_name: variableName,
        alias: variableName,
        color: color,
        axis: axis,
        line_style: 'solid'
      });
      
      atualizarListaDatasets();
      
      // Resetar selects
      $('#select-device').val('').trigger('change');
      $('#select-variable').val('').prop('disabled', true);
      $('#variable-axis').val('y');
      $('#variable-color').val('#1B7D3D');
      
      atualizarPreview();
    }
    
    // ===== ATUALIZAR LISTA DE DATASETS =====
    function atualizarListaDatasets() {
      const container = $('#datasets-list');
      
      if (datasets.length === 0) {
        container.html('<p class="text-muted">Nenhum dataset adicionado ainda.</p>');
        return;
      }
      
      let html = '';
      datasets.forEach((ds, index) => {
        html += `
          <div class="dataset-item">
            <button type="button" class="btn btn-danger btn-sm btn-remove-dataset" onclick="removerDataset(${index})">
              <i class="fas fa-trash"></i>
            </button>
            <div class="dataset-header">
              <div>
                <strong>${ds.device_name}</strong> / <code>${ds.variable_name}</code>
                <div style="margin-top: 5px;">
                  <span style="display: inline-block; width: 20px; height: 20px; background: ${ds.color}; border-radius: 3px; vertical-align: middle; margin-right: 5px;"></span>
                  <small>Eixo: <strong>${ds.axis.toUpperCase()}</strong></small>
                </div>
              </div>
            </div>
          </div>
        `;
      });
      
      container.html(html);
    }
    
    // ===== REMOVER DATASET =====
    window.removerDataset = function(index) {
      datasets.splice(index, 1);
      atualizarListaDatasets();
      atualizarPreview();
    }
    
    // ===== ATUALIZAR PREVIEW =====
    function atualizarPreview() {
      const container = document.getElementById('preview-message');
      
      if (datasets.length === 0) {
        container.innerHTML = `
          <div class="text-center text-muted">
            <p><i class="fas fa-chart-line fa-3x mb-3"></i></p>
            <p>Adicione datasets para visualizar preview</p>
          </div>
        `;
        return;
      }
      
      container.innerHTML = `<canvas id="previewChart"></canvas>`;
      
      // Dados fake para preview
      const labels = Array.from({length: 10}, (_, i) => `${i}:00`);
      const chartData = {
        labels: labels,
        datasets: datasets.map(ds => ({
          label: ds.alias + ` (${ds.device_name})`,
          data: Array.from({length: 10}, () => Math.floor(Math.random() * 100)),
          borderColor: ds.color,
          backgroundColor: ds.color + '33',
          tension: 0.1
        }))
      };
      
      const chartType = $('#chart-type').val() || 'linha';
      let chartTypeValue = converterTipoGrafico(chartType);
      
      // Se for tipo 'area', configurar preenchimento
      const isAreaChart = chartType === 'area';
      
      if (chartPreview) {
        chartPreview.destroy();
      }
      
      const datasetConfig = datasets.map(ds => {
        const config = {
          label: ds.alias + ` (${ds.device_name})`,
          data: Array.from({length: 10}, () => Math.floor(Math.random() * 100)),
          borderColor: ds.color,
          backgroundColor: isAreaChart ? ds.color + '33' : ds.color + '33',
          tension: 0.4
        };
        
        // Adicionar fill para gráfico de área
        if (isAreaChart) {
          config.fill = true;
        }
        
        return config;
      });
      
      chartPreview = new Chart(document.getElementById('previewChart'), {
        type: chartTypeValue,
        data: {
          labels: labels,
          datasets: datasetConfig
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          interaction: {
            mode: 'index',
            intersect: false,
          },
          plugins: {
            legend: { 
              display: true,
              position: 'top',
              labels: {
                usePointStyle: true,
                padding: 10
              }
            },
            tooltip: {
              enabled: true,
              mode: 'index',
              intersect: false,
              backgroundColor: 'rgba(0,0,0,0.8)',
              padding: 10,
              callbacks: {
                label: function(context) {
                  let label = context.dataset.label || '';
                  if (label) {
                    label += ': ';
                  }
                  if (context.parsed.y !== null) {
                    label += Number(context.parsed.y).toFixed(2);
                  }
                  return label;
                }
              }
            },
            zoom: chartTypeValue === 'pie' || chartTypeValue === 'doughnut' ? {} : {
              zoom: {
                wheel: {
                  enabled: true,
                  speed: 0.1
                },
                pinch: {
                  enabled: true
                },
                mode: 'x',
              },
              pan: {
                enabled: true,
                mode: 'x',
                modifierKey: 'shift'
              }
            }
          }
        }
      });
    }
    
    // ===== CONVERTER TIPO DE GRÁFICO - PT para EN =====
    function converterTipoGrafico(tipo) {
      const mapa = {
        'linha': 'line',
        'barra': 'bar',
        'pizza': 'pie',
        'rosca': 'doughnut',
        'area': 'line',  // Chart.js não tem tipo 'area', usa 'line' com fill
        'scatter': 'scatter'
      };
      return mapa[tipo] || 'line';  // Default para 'line' se tipo não reconhecido
    }
    
    // ===== SALVAR GRÁFICO AVANÇADO =====
    async function salvarGraficoAvancado() {
      const chartName = $('#chart-name').val();
      const chartType = $('#chart-type').val();
      
      if (!chartName || datasets.length === 0) {
        alert('Preencha o nome e adicione pelo menos um dataset');
        return;
      }
      
      const payload = {
        project_id: PROJECT_ID,
        name: chartName,
        chart_type: converterTipoGrafico(chartType),
        time_range: selectedTimeRange,
        date_start: $('#date-start').val() || null,
        date_end: $('#date-end').val() || null,
        is_public: $('#chart-is-public').is(':checked') ? 1 : 0,
        datasets: datasets
      };
      
      console.log('Payload enviado:', JSON.stringify(payload));
      
      try {
        const response = await fetch(API_SALVAR_GRAFICO_FORM, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          credentials: 'include'
        });
        
        const result = await safeJson(response);
        alert('Gráfico salvo com sucesso!');
        
        // Reset do formulário
        document.getElementById('form-grafico').reset();
        datasets = [];
        selectedTimeRange = 'all';
        atualizarListaDatasets();
        atualizarPreview();
        
        // Atualizar lista de gráficos salvos
        carregarGraficosSalvos();
        
        // Voltar para a aba de visão geral
        $('#tab-geral-tab').tab('show');
        
      } catch (error) {
        alert('Erro ao salvar: ' + error.message);
      }
    }
    
    // ===== INICIALIZAÇÃO FINAL =====
    // Select2
    $('.select2').select2({ theme: 'bootstrap' });
    
    // Event Listeners do formulário de gráficos
    $('#select-device').on('change', carregarVariaveisFormulario);
    $('#btn-add-dataset').on('click', adicionarDataset);
    $('#btn-salvar-grafico').on('click', salvarGraficoAvancado);

    // Event Listener do convite
    if (inviteButton) {
      inviteButton.addEventListener('click', enviarConvite);
    }
    
    // Event Listener de sair do projeto
    const btnSairProjeto = document.getElementById('btn-sair-projeto');
    if (btnSairProjeto) {
      btnSairProjeto.addEventListener('click', sairProjeto);
    }
    
    // Event Listener do delete project
    if (deleteProjectButton) {
      deleteProjectButton.addEventListener('click', deletarProjeto);
    }
    
    // Event Listener da visibilidade do projeto
    if (toggleProjectVisibility) {
      toggleProjectVisibility.addEventListener('change', function() {
        alterarVisibilidadeProjeto(this.checked);
      });
    }
    
    // Event Listeners de exportação
    const btnExportCsv = document.getElementById('btn-export-csv');
    const btnExportJson = document.getElementById('btn-export-json');
    const btnExportExcel = document.getElementById('btn-export-excel');
    
    if (btnExportCsv) {
      btnExportCsv.addEventListener('click', () => exportarDados('csv'));
    }
    if (btnExportJson) {
      btnExportJson.addEventListener('click', () => exportarDados('json'));
    }
    if (btnExportExcel) {
      btnExportExcel.addEventListener('click', () => exportarDados('excel'));
    }
    
    // Event Listeners dos filtros de solicitações
    document.querySelectorAll('.btn-filter-solicitacoes').forEach(btn => {
      btn.addEventListener('click', function() {
        const status = this.dataset.status;
        carregarSolicitacoes(status);
      });
    });
    
    // Event Listener para guardar dados do projeto atualizado
    document.getElementById('project-tabs').addEventListener('shown.bs.tab', function(e) {
      if (e.target.id === 'tab-solicitacoes-tab') {
        carregarSolicitacoes('pendente');
      }
    });
    
    $('.time-range-btn').on('click', function(e) {
      e.preventDefault();
      selecionarIntervaloTempo($(this));
    });
    
    // Carregar dados iniciais
    carregarDispositivosFormulario();
    carregarDadosPrincipais();
    carregarStats(); 
    carregarGraficosSalvos();
    carregarParticipantes();
  });

</script>
</body>
</html>
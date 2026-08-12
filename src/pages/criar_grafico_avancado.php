<?php 
require '../auth/auth_check.php';
require '../config/db.php';

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    header('Location: meus-projetos.php');
    exit;
}
$project_id_from_url = intval($_GET['project_id']);

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
  <title>Criar Gráfico Avançado | IFSentral</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tempusdominus-bootstrap-4/5.39.0/css/tempusdominus-bootstrap-4.min.css">
  
  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
      --ifsc-light: #2A9B4A;
    }
    
    .wrapper { display: flex; flex-direction: column; min-height: 100vh; }
    .content-wrapper { flex: 1; }
    
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
    
    .form-row-dataset {
      margin-bottom: 12px;
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
    
    .dataset-badge {
      display: inline-block;
      background: var(--ifsc-primary);
      color: white;
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 0.85rem;
      margin-right: 5px;
    }
    
    .card-primary .card-header {
      background-color: var(--ifsc-primary) !important;
    }
    
    .btn-primary, .btn-primary:hover {
      background-color: var(--ifsc-primary) !important;
      border-color: var(--ifsc-primary) !important;
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
  </style>
</head>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <?php require_once __DIR__ . '/../includes/header.php'; ?>
  
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <h1>Criar Gráfico Avançado</h1>
        <p class="text-muted">Configure múltiplos dispositivos, variáveis e escalas de tempo</p>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="row">
          <!-- FORMULÁRIO -->
          <div class="col-lg-6">
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title">Configuração do Gráfico</h3>
              </div>
              <form id="form-grafico">
                <div class="card-body">
                  
                  <!-- Nome e Tipo -->
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
                  
                  <!-- Intervalo de Tempo -->
                  <div class="form-group">
                    <label>Intervalo de Tempo:</label>
                    <div class="time-range-buttons">
                      <button type="button" class="btn btn-outline-primary time-range-btn" data-range="24h">Últimas 24h</button>
                      <button type="button" class="btn btn-outline-primary time-range-btn" data-range="7d">7 dias</button>
                      <button type="button" class="btn btn-outline-primary time-range-btn" data-range="30d">30 dias</button>
                      <button type="button" class="btn btn-outline-primary time-range-btn" data-range="all">Todos</button>
                    </div>
                  </div>
                  
                  <!-- Datas Customizadas -->
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
                  
                  <!-- Adicionar Dataset -->
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
          
          <!-- PREVIEW E DATASETS -->
          <div class="col-lg-6">
            <!-- Preview -->
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
            
            <!-- Lista de Datasets -->
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
        
        <!-- BOTÕES DE AÇÃO -->
        <div class="row mt-3">
          <div class="col-12">
            <button type="button" class="btn btn-primary btn-lg" id="btn-salvar-grafico">
              <i class="fas fa-save mr-2"></i> Salvar Gráfico
            </button>
            <a href="gerenciar-projeto.php?id=<?php echo $project_id_from_url; ?>" class="btn btn-secondary btn-lg">
              <i class="fas fa-times mr-2"></i> Cancelar
            </a>
          </div>
        </div>
      </div>
    </section>
  </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/tempusdominus-bootstrap-4/5.39.0/js/tempusdominus-bootstrap-4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<script src="../assets/js/fetch-helpers.js"></script>

<script>
const PROJECT_ID = <?php echo $project_id_from_url; ?>;
const API_DEVICES = '../api/listar_devices.php';
const API_CHAVES = '../api/obter_chaves_dispositivo.php';
const API_SALVAR_GRAFICO = '../api/salvar_grafico_avancado.php';

let datasets = [];
let selectedTimeRange = 'all';
let chartPreview = null;

// ===== INICIALIZAÇÃO =====
$(document).ready(function() {
  $('.select2').select2({ theme: 'bootstrap' });
  carregarDispositivos();
  
  // Event Listeners
  $('#select-device').on('change', carregarVariaveis);
  $('#btn-add-dataset').on('click', adicionarDataset);
  $('#btn-salvar-grafico').on('click', salvarGrafico);
  
  $('.time-range-btn').on('click', function(e) {
    e.preventDefault();
    selecionarIntervaloTempo($(this));
  });
});

// ===== CARREGAR DISPOSITIVOS =====
async function carregarDispositivos() {
  try {
    const response = await fetch(API_DEVICES, { credentials: 'include' });
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

// ===== CARREGAR VARIÁVEIS =====
async function carregarVariaveis() {
  const deviceId = $('#select-device').val();
  $('#select-variable').html('<option>-- Carregando --</option>').prop('disabled', true);
  
  if (!deviceId) {
    $('#select-variable').html('<option>Selecione um dispositivo</option>').prop('disabled', true);
    return;
  }
  
  try {
    const response = await fetch(`${API_CHAVES}?device_id=${deviceId}`, { credentials: 'include' });
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
function removerDataset(index) {
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
  
  const chartType = $('#chart-type').val() || 'line';
  
  if (chartPreview) {
    chartPreview.destroy();
  }
  
  chartPreview = new Chart(document.getElementById('previewChart'), {
    type: chartType,
    data: chartData,
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: { display: true }
      }
    }
  });
}

// ===== SALVAR GRÁFICO =====
async function salvarGrafico() {
  const chartName = $('#chart-name').val();
  const chartType = $('#chart-type').val();
  
  if (!chartName || datasets.length === 0) {
    alert('Preencha o nome e adicione pelo menos um dataset');
    return;
  }
  
  const payload = {
    project_id: PROJECT_ID,
    name: chartName,
    chart_type: chartType,
    time_range: selectedTimeRange,
    date_start: $('#date-start').val() || null,
    date_end: $('#date-end').val() || null,
    datasets: datasets
  };
  
  try {
    const response = await fetch(API_SALVAR_GRAFICO, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'include'
    });
    
    const result = await safeJson(response);
    alert('Gráfico salvo com sucesso!');
    window.location.href = `gerenciar-projeto.php?id=${PROJECT_ID}`;
  } catch (error) {
    alert('Erro ao salvar: ' + error.message);
  }
}
</script>

</body>
</html>

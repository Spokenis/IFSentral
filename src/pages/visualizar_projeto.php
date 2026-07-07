<?php 
require '../auth/auth_check.php';
require '../config/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: explorar_projetos.php');
    exit;
}
$project_id_from_url = intval($_GET['id']);

// Verificar se o projeto é público
try {
    $sql = "SELECT id, name, description, `public` FROM projects WHERE id = ? AND deletedAt IS NULL";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id_from_url]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        header('Location: explorar_projetos.php');
        exit;
    }
    
    // Se o projeto não for público, redirecionar
    if (!$project['public']) {
        header('Location: explorar_projetos.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: explorar_projetos.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($project['name']); ?> | IFSentral</title>

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
    #project-tags-container .badge {
        font-size: 0.9rem;
        margin-right: 5px;
        color: white !important;
    }
    .badge-warning { color: #212529 !important; }
    
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
    
    .grafico-card {
      margin-bottom: 20px;
    }
    
    .grafico-container {
      position: relative;
      min-height: 400px;
      padding: 15px;
    }
    
    canvas {
      max-height: 400px !important;
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
      <button class="navbar-toggler order-1" type="button" data-toggle="collapse" data-target="#navbarCollapse">
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
          <div class="col-sm-12">
            <h1><?php echo htmlspecialchars($project['name']); ?></h1>
            <span id="project-tags-container"></span>
            <p class="text-muted mt-2">
              <i class="fas fa-globe mr-1"></i>Projeto Público | 
              <a href="explorar_projetos.php">Voltar para Explorar Projetos</a>
            </p>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <!-- Informações do Projeto -->
        <div class="row">
          <div class="col-md-12">
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Informações do Projeto</h3>
              </div>
              <div class="card-body">
                <div class="row">
                  <div class="col-md-8">
                    <p><strong>Nome:</strong> <?php echo htmlspecialchars($project['name']); ?></p>
                    <p><strong>Descrição:</strong></p>
                    <p style="font-style: italic;"><?php echo htmlspecialchars($project['description'] ?: 'Sem descrição'); ?></p>
                  </div>
                  <div class="col-md-4">
                    <p><strong>Tags:</strong></p>
                    <div id="tags-container" style="margin-top: 10px;">Carregando...</div>
                    <p class="mt-3"><strong>Gerente:</strong> <span id="gerente-nome">Carregando...</span></p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Informações de Participação -->
        <div class="row">
          <div class="col-md-12">
            <div class="card card-info">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-users mr-2"></i>Participação no Projeto</h3>
              </div>
              <div class="card-body">
                <div class="row">
                  <div class="col-md-6">
                    <p><strong>Participantes Atuais:</strong> <span id="participantes-count">Carregando...</span></p>
                    <p><strong>Vagas Disponíveis:</strong> <span id="vagas-disponiveis">Carregando...</span></p>
                  </div>
                  <div class="col-md-6" id="join-button-container" style="display: flex; align-items: center; justify-content: flex-end;">
                    <!-- Será preenchido dinamicamente -->
                  </div>
                </div>
                <div id="join-status" style="margin-top: 15px;"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Gráficos Públicos -->
        <div class="row">
          <div class="col-md-12">
            <div class="card card-success">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>Gráficos Públicos</h3>
              </div>
              <div class="card-body">
                <div id="graficos-status" class="text-muted mb-3">Carregando gráficos...</div>
                <div id="graficos-container" class="row"></div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>
  </div>

  <footer class="main-footer text-center">
    <strong>Copyright &copy; 2024-2026 <a href="index.html">IFSentral</a>.</strong> Todos os direitos reservados.
  </footer>

</div>

<script>
    const PROJECT_ID = <?php echo $project_id_from_url; ?>;
  const CURRENT_USER_ID = <?php echo intval($_SESSION['user_id'] ?? 0); ?>;
</script>
<script src="../assets/js/fetch-helpers.js"></script>
<script src="../assets/js/profile-picture-helper.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>

<script>
$(function() {
    // APIs
    const API_LISTAR_TAGS = `../api/listar_tags.php?project_id=${PROJECT_ID}`;
    const API_LISTAR_GRAFICOS = `listar_graficos.php?project_id=${PROJECT_ID}`;
    const API_OBTER_DADOS_GRAFICO = '../api/obter_dados_grafico_renderizado.php';
    const API_LISTAR_PARTICIPANTES = `../api/listar_participantes.php?project_id=${PROJECT_ID}`;
    const API_ENVIAR_SOLICITACAO = '../api/enviar_solicitacao_participacao.php';
    
    let chartInstances = {};
    let isUserParticipant = false;
    let projectInfo = {};
    
    // Carregar tags do projeto
    async function carregarTags() {
        try {
            const response = await fetch(API_LISTAR_TAGS, { credentials: 'include' });
            const tags = await safeJson(response);
            
            const tagsContainer = document.getElementById('tags-container');
            const projectTagsContainer = document.getElementById('project-tags-container');
            
            if (tags && tags.length > 0) {
                const colors = ['primary', 'info', 'success', 'warning', 'danger'];
                let htmlTags = '';
                tags.forEach((tag, index) => {
                    const color = colors[index % colors.length];
                    htmlTags += `<span class="badge badge-${color} mr-1">${tag.name}</span> `;
                });
                tagsContainer.innerHTML = htmlTags;
                projectTagsContainer.innerHTML = htmlTags;
            } else {
                tagsContainer.innerHTML = '<span class="badge badge-secondary">Sem tags</span>';
                projectTagsContainer.innerHTML = '';
            }
        } catch (error) {
            console.error('Erro ao carregar tags:', error);
            document.getElementById('tags-container').innerHTML = '<span class="text-danger">Erro ao carregar tags</span>';
        }
    }
    
    // Carregar gerente do projeto
    async function carregarGerente() {
        try {
            const response = await fetch(API_LISTAR_PARTICIPANTES, { credentials: 'include' });
            const participantes = await response.json();
            
            const gerente = participantes.find(p => p.role_name === 'Gerente');
            if (gerente) {
                document.getElementById('gerente-nome').textContent = gerente.user_name;
            } else {
                document.getElementById('gerente-nome').textContent = 'Não identificado';
            }
        } catch (error) {
            console.error('Erro ao carregar gerente:', error);
            document.getElementById('gerente-nome').textContent = 'Erro ao carregar';
        }
    }
    
    // Carregar informações de participação
    async function carregarInfoParticipacao() {
        try {
            // Verificar se o usuário é participante
            const response = await fetch(API_LISTAR_PARTICIPANTES, { credentials: 'include' });
            const participantes = await response.json();
            
            if (!Array.isArray(participantes)) {
                // Se não é array, talvez tenha um erro. Assume que não é participante
                isUserParticipant = false;
            } else {
          isUserParticipant = participantes.some(p => parseInt(p.user_id) === CURRENT_USER_ID);
            }
            
            // Obter informações do projeto via API (pega do PHP inline mas poderia ser uma API)
            // Para agora, vamos fazer uma requisição simples para confirmar
            const containerEl = document.getElementById('join-button-container');
            const participantesEl = document.getElementById('participantes-count');
            const vagasEl = document.getElementById('vagas-disponiveis');
            
            // Contar participantes
            const totalParticipantes = Array.isArray(participantes) ? participantes.length : 0;
            participantesEl.textContent = totalParticipantes;
            
            // Para maxUsers, precisamos fazer uma requisição ou colocar inline do servidor
            // Por enquanto, vamos usar um placeholder que será atualizado
            // Se maxUsers for indefinido, mostramos como ilimitado
            const maxUsers = document.getElementById('max-users')?.textContent || 'Ilimitado';
            vagasEl.textContent = maxUsers === 'Ilimitado' ? 'Ilimitadas' : (parseInt(maxUsers) - totalParticipantes);
            
            // Mostrar botão apropriado
            if (isUserParticipant) {
                containerEl.innerHTML = '<span class="badge badge-success"><i class="fas fa-check mr-2"></i>Você é um participante deste projeto</span>';
            } else {
                containerEl.innerHTML = `
                    <button type="button" class="btn btn-primary" id="btn-solicitar-participacao">
                        <i class="fas fa-hand-paper mr-2"></i>Pedir para Participar
                    </button>
                `;
                
                // Adicionar evento ao botão
                document.getElementById('btn-solicitar-participacao').addEventListener('click', enviarSolicitacaoParticipacao);
            }
            
        } catch (error) {
            console.error('Erro ao carregar informações de participação:', error);
            document.getElementById('join-button-container').innerHTML = '<span class="badge badge-secondary">Não foi possível carregar informações</span>';
        }
    }
    
    // Enviar solicitação de participação
    async function enviarSolicitacaoParticipacao() {
        const buttonEl = document.getElementById('btn-solicitar-participacao');
        const statusEl = document.getElementById('join-status');
        
        buttonEl.disabled = true;
        buttonEl.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enviando solicitação...';
        statusEl.innerHTML = '';
        
        try {
            const response = await fetch(API_ENVIAR_SOLICITACAO, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    project_id: PROJECT_ID,
                    message: 'Gostaria de participar deste projeto'
                })
            });
            
            const result = await safeJson(response);
            
            if (result.success) {
                statusEl.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i>' + result.message + '</div>';
                document.getElementById('join-button-container').innerHTML = '<span class="badge badge-info"><i class="fas fa-hourglass-half mr-2"></i>Solicitação Pendente</span>';
            } else {
                statusEl.innerHTML = `<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>${result.error || result.message}</div>`;
                buttonEl.disabled = false;
                buttonEl.innerHTML = '<i class="fas fa-hand-paper mr-2"></i>Pedir para Participar';
            }
            
        } catch (error) {
            statusEl.innerHTML = `<div class="alert alert-danger"><i class="fas fa-times-circle mr-2"></i>Erro: ${error.message}</div>`;
            buttonEl.disabled = false;
            buttonEl.innerHTML = '<i class="fas fa-hand-paper mr-2"></i>Pedir para Participar';
        }
    }
    
    async function carregarGraficosPublicos() {
        const statusEl = document.getElementById('graficos-status');
        const containerEl = document.getElementById('graficos-container');
        
        try {
            const response = await fetch(API_LISTAR_GRAFICOS, { credentials: 'include' });
            const graficos = await safeJson(response);
            
            // Filtrar apenas gráficos públicos
            const graficosPublicos = graficos.filter(g => g.visibility === 'Publico');
            
            if (graficosPublicos.length === 0) {
                statusEl.innerHTML = '<div class="alert alert-info">Nenhum gráfico público disponível neste projeto.</div>';
                return;
            }
            
            statusEl.style.display = 'none';
            containerEl.innerHTML = '';
            
            // Renderizar cada gráfico
            for (const grafico of graficosPublicos) {
                await renderizarGrafico(grafico);
            }
            
        } catch (error) {
            statusEl.innerHTML = `<div class="alert alert-danger">Erro ao carregar gráficos: ${error.message}</div>`;
        }
    }
    
    // Renderizar um gráfico
    async function renderizarGrafico(grafico) {
        const containerEl = document.getElementById('graficos-container');
        const canvasId = `chart-${grafico.id}`;
        
        // Criar card do gráfico
        const cardHtml = `
            <div class="col-md-12 grafico-card">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>${grafico.name}</h3>
                        <div class="card-tools">
                            <span class="badge badge-success">Público</span>
                        </div>
                    </div>
                    <div class="card-body grafico-container">
                        <canvas id="${canvasId}"></canvas>
                    </div>
                </div>
            </div>
        `;
        
        containerEl.insertAdjacentHTML('beforeend', cardHtml);
        
        // Buscar dados do grafico
        try {
            const response = await fetch(`${API_OBTER_DADOS_GRAFICO}?chart_id=${grafico.id}`, {
                credentials: 'include'
            });
            
            const data = await safeJson(response);
            
            if (!data.success || !data.chart) {
                console.error('Erro ao carregar dados do gráfico:', grafico.name);
                return;
            }
            
            const chart = data.chart;
            const payloads = data.payloads || [];
            const datasetsInfo = data.datasets || [];
            const chartType = chart.chart_type || 'line';
            
            // Processar dados para Chart.js
            let chartDatasets = [];
            let labels = [];
            
            // Se é gráfico multi-device/avançado
            if (datasetsInfo && datasetsInfo.length > 0) {
                labels = payloads.map(p => new Date(p.timestamp).toLocaleDateString('pt-BR'));
                
                datasetsInfo.forEach((ds, index) => {
                    const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'];
                    const color = ds.color || colors[index % colors.length];
                    
                    // Extrair valores para este dataset
                    const values = payloads.map(p => {
                        try {
                            const json = typeof p.payload === 'string' ? JSON.parse(p.payload) : p.payload;
                            return json[ds.variable_name] || null;
                        } catch (e) {
                            return null;
                        }
                    });
                    
                    chartDatasets.push({
                        label: ds.alias || `${ds.device_name} - ${ds.variable_name}`,
                        data: values,
                        borderColor: color,
                        backgroundColor: chartType === 'area' ? color + '33' : color + '33',
                        borderWidth: 2,
                        fill: chartType === 'area',
                        yAxisID: ds.axis || 'y',
                        tension: 0.4
                    });
                });
            } else {
                // Gráfico simples com um json_key
                labels = payloads.map(p => new Date(p.timestamp).toLocaleDateString('pt-BR'));
                const values = payloads.map(p => {
                    try {
                        const json = typeof p.payload === 'string' ? JSON.parse(p.payload) : p.payload;
                        return json[chart.json_key] || null;
                    } catch (e) {
                        return null;
                    }
                });
                
                chartDatasets.push({
                    label: chart.json_key,
                    data: values,
                    borderColor: '#1B7D3D',
                    backgroundColor: chartType === 'area' ? '#1B7D3D33' : '#1B7D3D33',
                    borderWidth: 2,
                    fill: chartType === 'area',
                    tension: 0.4
                });
            }
            
            // Criar gráfico
            const ctx = document.getElementById(canvasId).getContext('2d');
            const chartInstance = new Chart(ctx, {
                type: chartType === 'area' ? 'line' : chartType,
                data: {
                    labels: labels,
                    datasets: chartDatasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        title: {
                            display: false
                        },
                        zoom: {
                            zoom: {
                                wheel: {
                                    enabled: true
                                },
                                pinch: {
                                    enabled: true
                                },
                                mode: 'x'
                            },
                            pan: {
                                enabled: true,
                                mode: 'x'
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Data/Hora'
                            }
                        },
                        y: {
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Valor'
                            }
                        }
                    }
                }
            });
            
            chartInstances[canvasId] = chartInstance;
            
        } catch (error) {
            console.error('Erro ao renderizar gráfico:', error);
            document.getElementById(canvasId).parentElement.innerHTML = 
                '<div class="alert alert-danger">Erro ao carregar dados do gráfico</div>';
        }
    }
    
    // Inicialização
    carregarTags();
    carregarGerente();
    carregarInfoParticipacao();
    carregarGraficosPublicos();
});
</script>

</body>
</html>

<?php 
require '../auth/auth_check.php';
require '../config/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: /explorar-projetos');
    exit;
}
$project_id_from_url = intval($_GET['id']);

// Verificar se o projeto é público
try {
    $sql = "
        SELECT 
            p.id, 
            p.name, 
            p.description, 
            p.`public`,
            p.maxUsers,
            GROUP_CONCAT(t.name SEPARATOR ',') AS project_tags
        FROM projects p
        LEFT JOIN project_tags pt ON p.id = pt.project_id
        LEFT JOIN tags t ON pt.tag_id = t.id
        WHERE p.id = ? AND p.deletedAt IS NULL
        GROUP BY p.id, p.name, p.description, p.`public`, p.maxUsers
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id_from_url]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        header('Location: /explorar-projetos');
        exit;
    }
    
    // Se o projeto não for público, redirecionar
    if (!$project['public']) {
        header('Location: /explorar-projetos');
        exit;
    }
} catch (Exception $e) {
    header('Location: /explorar-projetos');
    exit;
}

// --- Lógica para montar o HTML das Tags ---
$tagsHtml = '';
if (!empty($project['project_tags'])) {
    $tagsArray = array_filter(array_map('trim', explode(',', $project['project_tags'])));
    foreach ($tagsArray as $tag) {
        $tagsHtml .= '<span class="tag-projeto">' . htmlspecialchars($tag) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($project['name']); ?> | IFSCentral</title>

  <link rel="stylesheet" href="/assets/vendor/fonts/source-sans-pro.css">
  <link rel="stylesheet" href="/assets/vendor/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/assets/vendor/admin-lte/3.2.0/css/adminlte.min.css">
  
  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
      --ifsc-light: #2A9B4A;
    }
    
    .wrapper { display: flex; flex-direction: column; min-height: 100vh; }
    .content-wrapper { flex: 1; }
    .link-voltar { display: inline-block; margin-bottom: .5rem; color: var(--ifsc-primary); font-weight: 600; }
    .link-voltar:hover { color: var(--ifsc-secondary); text-decoration: none; }
    .content-header h1 { font-size: 2rem; font-weight: 600; margin: 0; }
    .meta-projeto { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-top: .6rem; }
    .selo-publico { display: inline-flex; align-items: center; gap: .35rem; padding: .2rem .65rem; border-radius: 1rem;
                    background: var(--ifsc-primary); color: #fff; font-size: .85rem; font-weight: 600; }
    .tag-projeto { display: inline-block; padding: .2rem .65rem; border-radius: 1rem; background: #e6f2ea;
                   border: 1px solid #c3e0cc; color: var(--ifsc-secondary); font-size: .85rem; font-weight: 600; }
    .rotulo { display: block; margin-bottom: .25rem; color: #6c757d; font-size: .78rem; font-weight: 700;
              text-transform: uppercase; letter-spacing: .04em; }
    .descricao-projeto { font-size: 1.05rem; margin: 0; white-space: pre-line; }
    .dados-projeto { margin: 0; }
    .dados-projeto > div { display: flex; justify-content: space-between; gap: 1rem; padding: .55rem 0; border-bottom: 1px solid #edf0f2; }
    .dados-projeto > div:last-child { border-bottom: 0; }
    .dados-projeto dt { margin: 0; color: #6c757d; font-weight: 600; }
    .dados-projeto dd { margin: 0; text-align: right; font-weight: 600; }
    @media (min-width: 768px) { .coluna-dados { border-left: 1px solid #edf0f2; } }
    #join-button-container { margin-top: 1rem; }
    #join-button-container .btn { width: 100%; }
    .status-participacao { display: flex; align-items: center; justify-content: center; gap: .5rem; padding: .55rem .75rem;
                           border-radius: .3rem; background: #e6f2ea; color: var(--ifsc-secondary); font-weight: 600; }
    .status-participacao.pendente { background: #fff6dd; color: #7a5b00; }
    /* Cartão de cada gráfico, dentro da seção verde: cabeçalho claro */
    .grafico-card .card { box-shadow: none; border: 1px solid #e3e7ea; }
    .grafico-card .card .card-header { background: #f8f9fa !important; border-bottom: 1px solid #e3e7ea; }
    .grafico-card .card-title { color: #343a40; font-weight: 600; }
    .grafico-card .card-title i { color: var(--ifsc-primary); }
    .estado-vazio { padding: 2rem 1rem; text-align: center; color: #6c757d; }
    .estado-vazio i { display: block; margin-bottom: .6rem; font-size: 2rem; color: #c3e0cc; }
    
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

  <?php require_once __DIR__ . '/../includes/header.php'; ?>
  
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <div class="row mb-2">
          <div class="col-sm-12">
            <a href="/explorar-projetos" class="link-voltar"><i class="fas fa-arrow-left mr-1"></i>Explorar Projetos</a>
            <h1><?php echo htmlspecialchars($project['name']); ?></h1>
            <div class="meta-projeto">
              <span class="selo-publico"><i class="fas fa-globe"></i>Projeto público</span>
              <?php echo $tagsHtml; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <!-- Informações e participação -->
        <div class="card card-primary">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Sobre o projeto</h3>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-7 mb-3 mb-md-0">
                <span class="rotulo">Descrição</span>
                <?php if (!empty($project['description'])): ?>
                  <p class="descricao-projeto"><?php echo htmlspecialchars($project['description']); ?></p>
                <?php else: ?>
                  <p class="descricao-projeto text-muted font-italic">Sem descrição.</p>
                <?php endif; ?>
              </div>
              <div class="col-md-5 coluna-dados">
                <dl class="dados-projeto">
                  <div><dt>Gerente</dt><dd id="gerente-nome">Carregando...</dd></div>
                  <div><dt>Participantes</dt><dd id="participantes-count">Carregando...</dd></div>
                  <div><dt>Vagas disponíveis</dt><dd id="vagas-disponiveis">Carregando...</dd></div>
                </dl>
                <div id="join-button-container"></div>
                <div id="join-status" class="mt-2"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Gráficos Públicos -->
        <div class="row">
          <div class="col-md-12">
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>Gráficos públicos</h3>
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

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>

</div>

<script>
    const PROJECT_ID = <?php echo $project_id_from_url; ?>;
  const CURRENT_USER_ID = <?php echo intval($_SESSION['user_id'] ?? 0); ?>;
</script>
<script src="/assets/js/fetch-helpers.js"></script>
<script src="/assets/js/profile-picture-helper.js"></script>
<script src="/assets/js/grafico-xy.js"></script>
<script src="/assets/vendor/jquery/3.7.1/jquery.min.js"></script>
<script src="/assets/vendor/bootstrap/4.6.2/bootstrap.bundle.min.js"></script>
<script src="/assets/vendor/chart.js/4.6.1/chart.umd.min.js"></script>
<script src="/assets/vendor/hammerjs/2.0.8/hammer.min.js"></script>
<script src="/assets/vendor/chartjs-plugin-zoom/2.0.1/chartjs-plugin-zoom.min.js"></script>
<script src="/assets/vendor/admin-lte/3.2.0/js/adminlte.min.js"></script>

<script>
$(function() {
    // APIs
    const API_LISTAR_GRAFICOS = `/api/listar-graficos?project_id=${PROJECT_ID}`;

    const API_OBTER_DADOS_GRAFICO = '/api/obter-dados-grafico-renderizado';
    const API_LISTAR_PARTICIPANTES = `/api/listar-participantes?project_id=${PROJECT_ID}`;
    const API_ENVIAR_SOLICITACAO = '/api/enviar-solicitacao-participacao';
    
    const MAX_USERS = <?php echo $project['maxUsers'] !== null ? (int)$project['maxUsers'] : 'null'; ?>; // null = sem limite
    // Texto do usuário (ex.: nome do gráfico) vai para o HTML sempre escapado
    function escapeHtml(texto) {
        return String(texto ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
    let chartInstances = {};
    let graficosRenderizados = [];
    let isUserParticipant = false;
    let projectInfo = {};
    
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
            vagasEl.textContent = MAX_USERS === null ? 'Ilimitadas' : Math.max(0, MAX_USERS - totalParticipantes);
            
            // Mostrar botão apropriado
            if (isUserParticipant) {
                containerEl.innerHTML = '<div class="status-participacao"><i class="fas fa-check-circle"></i>Você participa deste projeto</div>';
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
            document.getElementById('join-button-container').innerHTML = '<span class="text-muted">Não foi possível carregar a participação.</span>';
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
                document.getElementById('join-button-container').innerHTML = '<div class="status-participacao pendente"><i class="fas fa-hourglass-half"></i>Solicitação enviada, aguardando o gerente</div>';
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
                statusEl.innerHTML = '<div class="estado-vazio"><i class="fas fa-chart-line"></i>Nenhum gráfico público neste projeto ainda.</div>';
                return;
            }
            
            statusEl.style.display = 'none';
            containerEl.innerHTML = '';
            
            graficosRenderizados = graficosPublicos;

            // Renderizar cada gráfico
            for (const grafico of graficosPublicos) {
                await renderizarGrafico(grafico);
            }
            
        } catch (error) {
            statusEl.innerHTML = `<div class="alert alert-danger">Erro ao carregar gráficos: ${error.message}</div>`;
        }
    }
    
    // Busca os dados de um gráfico e monta labels/datasets do Chart.js
    async function montarDadosGrafico(grafico) {
        const response = await fetch(`${API_OBTER_DADOS_GRAFICO}?chart_id=${grafico.id}`, {
            credentials: 'include'
        });
        
        const data = await safeJson(response);
        
        if (!data.success || !data.chart) {
            console.error('Erro ao carregar dados do gráfico:', grafico.name);
            return null;
        }
        
        // Nada para desenhar: o motivo vem da API
        if (data.aviso) {
            return { aviso: data.aviso };
        }

        // Gráfico X-Y (variável x variável): configuração própria
        if (data.modo === 'xy') {
            return { xy: montarConfigXY(data) };
        }

        const chart = data.chart;
        const payloads = data.payloads || [];
        const datasetsInfo = data.datasets || [];
        const chartType = chart.chart_type || 'line';
        
        // Processar dados para Chart.js
        let chartDatasets = [];
        let labels = [];
        
        // Se é gráfico multi-device/avançado
        // A API devolve os pontos já extraídos em datasets[].data ({x, y});
        // o formato antigo (payloads brutos) fica como alternativa.
        const hasPointData = datasetsInfo.some(ds => Array.isArray(ds.data));
        const xValues = hasPointData
            ? [...new Set(datasetsInfo.flatMap(ds => (ds.data || []).map(pt => pt.x)))].sort()
            : [];
        const formatX = (x) => new Date(String(x).replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

        if (datasetsInfo && datasetsInfo.length > 0) {
            labels = hasPointData
                ? xValues.map(formatX)
                : payloads.map(p => new Date(p.timestamp).toLocaleDateString('pt-BR'));
            
            datasetsInfo.forEach((ds, index) => {
                const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'];
                const color = ds.color || colors[index % colors.length];
                
                // Extrair valores para este dataset
                const pointMap = new Map((ds.data || []).map(pt => [pt.x, pt.y]));
                const values = hasPointData ? xValues.map(x => pointMap.has(x) ? pointMap.get(x) : null) : payloads.map(p => {
                    try {
                        const json = typeof p.payload === 'string' ? JSON.parse(p.payload) : p.payload;
                        return json[ds.variable_name] || null;
                    } catch (e) {
                        return null;
                    }
                });
                
                const nomeSerie = ds.alias || ds.variable_name;
                chartDatasets.push({
                    label: datasetsInfo.length > 1 && ds.device_name ? `${nomeSerie} (${ds.device_name})` : nomeSerie,
                    data: values,
                    borderColor: color,
                    backgroundColor: chartType === 'bar' ? color + 'B3' : color + '33', // barras quase opacas
                    borderWidth: 2,
                    fill: chartType === 'area',

                    tension: 0.4,
                    spanGaps: true // séries de dispositivos diferentes não têm pontos nos mesmos instantes
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
        
        return { labels, chartDatasets, chartType };
    }

    // Desenha o gráfico no canvas, ou atualiza os dados se ele já existe. Sem
    // dados, mostra o motivo no lugar do quadro em branco
    async function desenharGrafico(grafico, canvasId) {
        const canvasEl = document.getElementById(canvasId);
        if (!canvasEl) return;
        const dados = await montarDadosGrafico(grafico);
        if (!dados) return;
        if (dados.aviso) {
            mostrarAvisoGrafico(canvasEl, dados.aviso);
            delete chartInstances[canvasId];
            return;
        }
        mostrarAvisoGrafico(canvasEl, null);

        const instancia = chartInstances[canvasId];
        if (instancia) {
            if (instancia.isZoomedOrPanned && instancia.isZoomedOrPanned()) return;
            if (dados.xy) {
                instancia.data.datasets = dados.xy.data.datasets;
            } else {
                instancia.data.labels = dados.labels;
                instancia.data.datasets = dados.chartDatasets;
            }
            instancia.update('none');
            return;
        }
        if (dados.xy) {
            chartInstances[canvasId] = new Chart(canvasEl.getContext('2d'), dados.xy);
            return;
        }
        const { labels, chartDatasets, chartType } = dados;

        // Criar gráfico
        const ctx = document.getElementById(canvasId).getContext('2d');
        const chartInstance = new Chart(ctx, {
            type: ['area', 'scatter'].includes(chartType) ? 'line' : chartType,
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
                        <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>${escapeHtml(grafico.name)}</h3>
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
            await desenharGrafico(grafico, canvasId);
        } catch (error) {
            console.error('Erro ao renderizar gráfico:', error);
            document.getElementById(canvasId).parentElement.innerHTML = 
                '<div class="alert alert-danger">Erro ao carregar dados do gráfico</div>';
        }
    }
    
    // Dados novos entram nos gráficos sem recarregar a página. Com zoom, espera
    // o usuário voltar ao normal
    setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        (graficosRenderizados || []).forEach(grafico => {
            desenharGrafico(grafico, `chart-${grafico.id}`).catch(error => console.error('Erro ao atualizar gráfico:', error));
        });
    }, 60000);

    // Inicialização
    carregarGerente();

    carregarInfoParticipacao();
    carregarGraficosPublicos();
});
</script>

</body>
</html>

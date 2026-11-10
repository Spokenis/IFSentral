/**
 * Funções comuns dos gráficos (página do projeto e página pública).
 *
 * Gráfico X-Y (ex.: temperatura x pressão): cada ponto é um par de leituras do
 * mesmo instante, com uma variável no eixo horizontal no lugar do tempo.
 * Monta a configuração do Chart.js a partir da resposta de
 * api/obter-dados-grafico-renderizado quando ela vem com modo 'xy'.
 */
const CORES_GRAFICO_XY = ['#1B7D3D', '#1F77B4', '#FF7F0E', '#D62728', '#9467BD', '#17BECF'];

function formatarNumeroXY(valor) {
    return Number(valor).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
}

function montarConfigXY(dados) {
    const eixoX = dados.eixo_x;
    const nomeX = eixoX.alias || eixoX.variable_name;
    // O dispositivo entra no nome do eixo quando as séries vêm de outro dispositivo
    const rotuloX = eixoX.device_name && dados.datasets.some(ds => ds.device_name !== eixoX.device_name)
        ? `${nomeX} (${eixoX.device_name})` : nomeX;

    const datasets = dados.datasets.map((ds, i) => {
        const cor = ds.color || CORES_GRAFICO_XY[i % CORES_GRAFICO_XY.length];
        const nome = ds.alias || ds.variable_name;
        return {
            label: dados.datasets.length > 1 && ds.device_name ? `${nome} (${ds.device_name})` : nome,
            data: (ds.data || []).map(p => ({ x: p.x, y: p.y, t: p.t })),
            borderColor: cor,
            backgroundColor: cor + '99',
            pointRadius: 4,
            pointHoverRadius: 6,
            showLine: false
        };
    });

    return {
        type: 'scatter',
        data: { datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { type: 'linear', title: { display: true, text: rotuloX } },
                y: { title: { display: true, text: datasets.length === 1 ? datasets[0].label : 'Valor' } }
            },
            plugins: {
                legend: { display: true, position: 'top', labels: { usePointStyle: true, padding: 15 } },
                tooltip: {
                    callbacks: {
                        label: contexto => {
                            const p = contexto.raw;
                            const quando = p.t ? new Date(p.t).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
                            return `${contexto.dataset.label}: ${formatarNumeroXY(p.y)} | ${rotuloX}: ${formatarNumeroXY(p.x)}${quando ? ' (' + quando + ')' : ''}`;
                        }
                    }
                },
                zoom: {
                    zoom: { wheel: { enabled: true, speed: 0.1 }, pinch: { enabled: true }, mode: 'xy' },
                    pan: { enabled: true, mode: 'xy', modifierKey: 'shift' }
                }
            }
        }
    };
}

/**
 * Mostra um aviso no lugar do gráfico (ex.: "Sem leituras nas últimas 24
 * horas") ou, com texto vazio, tira o aviso e volta o canvas. Devolve true
 * quando o aviso ficou na tela.
 */
function mostrarAvisoGrafico(canvas, texto) {
    let aviso = canvas.parentElement.querySelector('.aviso-grafico');
    if (!texto) {
        if (aviso) aviso.remove();
        canvas.style.display = '';
        return false;
    }
    const existente = Chart.getChart(canvas);
    if (existente) existente.destroy();
    if (!aviso) {
        aviso = document.createElement('div');
        aviso.className = 'aviso-grafico';
        aviso.innerHTML = '<i class="fas fa-chart-line"></i><span></span>';
        canvas.parentElement.appendChild(aviso);
    }
    aviso.querySelector('span').textContent = texto;
    canvas.style.display = 'none';
    return true;
}

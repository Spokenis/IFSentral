<?php
/**
 * API: Obter Dados do Gráfico com Payload
 * Retorna os dados reais para renderizar um gráfico (Otimizado via Banco de Dados com Bucketing Dinâmico)
 */

require_once __DIR__ . '/../config/config.php';
setupSecureCORS();
require_once __DIR__ . '/../core/ApiError.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../core/Agregados.php';

use App\Core\Agregados;

// Cache curto do resultado de cada gráfico. A página do projeto recarrega os
// gráficos a cada 60 s, e várias pessoas olhando o mesmo projeto pediam o mesmo
// cálculo; agora ele é feito no máximo uma vez a cada GRAFICO_CACHE_TTL segundos
// por servidor. Dado novo aparece com até esse atraso.
const GRAFICO_CACHE_TTL = 30;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    if (!isset($_GET['chart_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'chart_id é obrigatório']);
        exit;
    }

    $chart_id = intval($_GET['chart_id']);
    $user_id = $_SESSION['user_id'] ?? null;

    // Buscar informações do gráfico
    $chartSql = "SELECT c.*, p.id as project_id, p.public as project_public
                 FROM charts c 
                 JOIN projects p ON c.project_id = p.id 
                 WHERE c.id = ?";
    
    $chartStmt = $conn->prepare($chartSql);
    $chartStmt->execute([$chart_id]);
    $chart = $chartStmt->fetch(PDO::FETCH_ASSOC);

    if (!$chart) {
        http_response_code(404);
        echo json_encode(['error' => 'Gráfico não encontrado']);
        exit;
    }

    // Validar permissão: participante do projeto vê qualquer gráfico dele; os
    // demais só veem gráfico público de projeto público. (Antes bastava um dos
    // dois ser público: o gráfico privado de um projeto público ficava aberto
    // a quem trocasse o chart_id na URL.)
    if (!($chart['is_public'] && $chart['project_public'])) {
        $authSql = "SELECT 1 FROM users_projects WHERE project_id = ? AND user_id = ?";
        $authStmt = $conn->prepare($authSql);
        $authStmt->execute([$chart['project_id'], $user_id]);

        if (!$authStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Permissão negada']);
            exit;
        }
    }

    // --- PROCESSAMENTO E EXTRAÇÃO DIRETA VIA BANCO DE DADOS ---

    // 1. Unificar a descoberta de datasets (Multi-dispositivo ou Simples/Legado)
    // As variáveis ficam em chart_datasets também nos gráficos de uma variável
    // só (com a cor escolhida); só os gráficos mais antigos, sem nenhuma linha
    // lá, usam as colunas device_id/json_key da tabela charts
    $datasetsSql = "
        SELECT cd.*, d.name as device_name
        FROM chart_datasets cd
        LEFT JOIN devices d ON cd.device_id = d.id
        WHERE cd.chart_id = ?
        ORDER BY cd.sort_order
    ";
    $datasetsStmt = $conn->prepare($datasetsSql);
    $datasetsStmt->execute([$chart_id]);
    $datasets = $datasetsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$datasets) {
        // Fallback e retrocompatibilidade para gráficos simples que usam colunas da tabela principal
        $chartConfig = json_decode($chart['config'] ?? '{}', true);
        $datasets[] = [
            'id' => null,
            'device_id' => $chart['device_id'],
            'device_name' => null,
            'variable_name' => $chart['json_key'],
            'alias' => $chart['json_key'],
            'color' => $chartConfig['theme_color'] ?? '#3182ce',
            'line_style' => 'solid',
            'axis' => 'y'
        ];
    }

    // --- CACHE (depois da checagem de permissão: o resultado não depende de
    // quem pede, mas só quem pode ver o gráfico chega aqui). A chave inclui a
    // configuração do gráfico e das séries, então editar o gráfico invalida.
    $cacheDir = sys_get_temp_dir() . '/ifscentral-graficos';
    $cacheArq = $cacheDir . '/' . $chart_id . '-' . md5(json_encode([$chart, $datasets])) . '.json';
    $cacheValido = fn() => is_file($cacheArq) && filemtime($cacheArq) > time() - GRAFICO_CACHE_TTL;
    if ($cacheValido()) {
        header('X-Cache: HIT');
        readfile($cacheArq);
        exit;
    }
    // Um cálculo por vez para cada gráfico: quem chega enquanto ele roda espera
    // e usa o resultado, em vez de repetir a mesma consulta pesada
    @mkdir($cacheDir, 0700, true);
    $cacheTrava = @fopen($cacheDir . '/' . $chart_id . '.lock', 'c');
    if ($cacheTrava) {
        flock($cacheTrava, LOCK_EX);
        clearstatcache(true, $cacheArq);
        if ($cacheValido()) {
            flock($cacheTrava, LOCK_UN);
            header('X-Cache: HIT');
            readfile($cacheArq);
            exit;
        }
    }

    // Período do gráfico, em segundos desde 1970 (independe do fuso do PHP e do
    // MySQL). Datas personalizadas têm prioridade; senão vale o botão escolhido
    // ao criar o gráfico (24h, 7d, 30d), recalculado a cada carregamento para a
    // janela acompanhar o tempo. "Todos" usa os últimos 30 dias, para não
    // agregar o histórico inteiro do dispositivo a cada carregamento (o GROUP
    // BY roda ANTES do LIMIT).
    $now = time();
    $range_start = null;
    $range_end = null;
    if (!empty($chart['date_start']) || !empty($chart['date_end'])) {
        $range_start = !empty($chart['date_start']) ? strtotime($chart['date_start']) : null;
        $range_end = !empty($chart['date_end']) ? strtotime($chart['date_end']) : null;
    } else {
        // "Todos": sem limite de data (antes eram só os últimos 30 dias, e um
        // dispositivo parado há mais tempo deixava o gráfico em branco). O
        // custo fica limitado pelos 2000 blocos mais recentes, abaixo.
        $ranges = ['24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400];
        $periodo = $ranges[$chart['time_range'] ?? 'all'] ?? null;
        $range_start = $periodo ? $now - $periodo : null;
    }

    $device_ids = array_values(array_unique(array_map(function ($ds) {
        return (int)$ds['device_id'];
    }, $datasets)));

    // Tamanho do bloco de agregação, pelo trecho que TEM dados: um dispositivo
    // novo num gráfico de 30 dias tem pontos de minuto em minuto, não médias
    // de 2 horas. Um bloco só para todas as séries, para que se alinhem.
    $bucket_seconds = 60;
    if ($device_ids) {
        $spanSql = "SELECT MIN(UNIX_TIMESTAMP(created_at)), MAX(UNIX_TIMESTAMP(created_at)) FROM device_payloads
                    WHERE device_id IN (" . implode(',', array_fill(0, count($device_ids), '?')) . ")";
        $spanParams = $device_ids;
        if ($range_start !== null) {
            $spanSql .= " AND created_at >= FROM_UNIXTIME(?)";
            $spanParams[] = $range_start;
        }
        if ($range_end !== null) {
            $spanSql .= " AND created_at <= FROM_UNIXTIME(?)";
            $spanParams[] = $range_end;
        }
        $spanStmt = $conn->prepare($spanSql);
        $spanStmt->execute($spanParams);
        [$first, $last] = $spanStmt->fetch(PDO::FETCH_NUM) ?: [null, null];
        if ($first !== null) {
            $span_hours = ((int)$last - (int)$first) / 3600;
            if ($span_hours > 24 * 31) {        // mais de um mês -> 1 dia
                $bucket_seconds = 86400;
            } elseif ($span_hours > 24 * 7) {   // mais de 7 dias -> 1 hora
                $bucket_seconds = 3600;
            } elseif ($span_hours > 24) {       // mais de 1 dia -> 15 minutos
                $bucket_seconds = 900;
            } elseif ($span_hours > 6) {        // mais de 6 horas -> 5 minutos
                $bucket_seconds = 300;
            }
        }
    }

    // 2. Extração dos pontos das séries
    // Média de uma variável em cada bloco de tempo: [bloco => valor]
    // Tabelas de valores já extraídos (ver core/Agregados.php), quando a cópia
    // dos dados antigos já terminou: blocos de 1 h ou mais vêm das somas por
    // hora; blocos menores, dos valores numéricos. Sem JSON em nenhum dos dois.
    $usarAgregados = Agregados::prontos($conn);
    $serie = function ($device_id, $var_name) use ($conn, $bucket_seconds, $range_start, $range_end, $usarAgregados) {
        if ($usarAgregados) {
            if ($bucket_seconds >= 3600) {
                $sql = "SELECT FLOOR(hora * 3600 / ?) AS bucket, SUM(soma) / SUM(qtd) AS y
                        FROM device_payload_hourly WHERE device_id = ? AND variavel = ?";
                $params = [$bucket_seconds, $device_id, $var_name];
                if ($range_start !== null) {
                    $sql .= " AND hora >= FLOOR(? / 3600)";
                    $params[] = $range_start;
                }
                if ($range_end !== null) {
                    $sql .= " AND hora <= FLOOR(? / 3600)";
                    $params[] = $range_end;
                }
            } else {
                $sql = "SELECT FLOOR(UNIX_TIMESTAMP(created_at) / ?) AS bucket, AVG(valor) AS y
                        FROM device_payload_values WHERE device_id = ? AND variavel = ?";
                $params = [$bucket_seconds, $device_id, $var_name];
                if ($range_start !== null) {
                    $sql .= " AND created_at >= FROM_UNIXTIME(?)";
                    $params[] = $range_start;
                }
                if ($range_end !== null) {
                    $sql .= " AND created_at <= FROM_UNIXTIME(?)";
                    $params[] = $range_end;
                }
            }
            // Os 2000 blocos mais recentes, em ordem cronológica (como no caminho antigo)
            $sql = "SELECT * FROM ($sql GROUP BY bucket ORDER BY bucket DESC LIMIT 2000) AS ultimos ORDER BY bucket ASC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $valores = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['y'] !== null) {
                    $valores[(int)$row['bucket']] = (float)$row['y'];
                }
            }
            return $valores;
        }

        $data_sql = "
            SELECT
                MIN(FLOOR(UNIX_TIMESTAMP(dp.created_at) / ?)) AS bucket,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(dp.payload, CONCAT('$.', ?))) AS DECIMAL(16,4))) AS y
            FROM
                device_payloads dp
            WHERE
                dp.device_id = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(dp.payload, CONCAT('$.', ?))) REGEXP '^-?[0-9]+([.][0-9]+)?([eE][-+]?[0-9]+)?$'
        ";
        // Só valores numéricos: texto (ex.: "status": "ok") virava zero na média
        $params = [$bucket_seconds, $var_name, $device_id, $var_name];
        if ($range_start !== null) {
            $data_sql .= " AND dp.created_at >= FROM_UNIXTIME(?)";
            $params[] = $range_start;
        }
        if ($range_end !== null) {
            $data_sql .= " AND dp.created_at <= FROM_UNIXTIME(?)";
            $params[] = $range_end;
        }
        // Os 2000 blocos mais recentes, em ordem cronológica
        $data_sql = "SELECT * FROM (" . $data_sql . "
            GROUP BY FLOOR(UNIX_TIMESTAMP(dp.created_at) / ?)
            ORDER BY bucket DESC
            LIMIT 2000
        ) AS ultimos ORDER BY bucket ASC";
        $params[] = $bucket_seconds;

        $stmt = $conn->prepare($data_sql);
        $stmt->execute($params);
        $valores = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['y'] !== null) {
                $valores[(int)$row['bucket']] = (float)$row['y'];
            }
        }
        return $valores;
    };
    // Instante do bloco em ISO 8601 UTC: o navegador mostra no fuso local
    $instante = fn($bucket) => gmdate('Y-m-d\TH:i:s\Z', $bucket * $bucket_seconds);

    // Variável no eixo X (gráfico X-Y, ex.: temperatura x pressão) ou o tempo
    // Só é X-Y o gráfico salvo com x_axis_var (opção "Outra variável"). Gráficos
    // antigos tinham um campo de eixo por variável que não valia nada: os
    // marcados com "X" continuam desenhados no tempo, como sempre foram.
    $eixo_x = null;
    foreach ($datasets as $dataset) {
        if (!empty($chart['x_axis_var']) && ($dataset['axis'] ?? 'y') === 'x'
            && preg_match('/^[a-zA-Z0-9_]+$/', $dataset['variable_name'])) {
            $eixo_x = $dataset;
        }
    }
    $valores_x = $eixo_x ? $serie($eixo_x['device_id'], $eixo_x['variable_name']) : null;

    $rendered_datasets = [];
    foreach ($datasets as $dataset) {
        $var_name = $dataset['variable_name'];

        // Proteção contra caminhos maliciosos ou injeção de caracteres no path do JSON
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $var_name) || ($eixo_x && $dataset === $eixo_x)) {
            continue;
        }

        $data_points = [];
        foreach ($serie($dataset['device_id'], $var_name) as $bucket => $valor) {
            if ($eixo_x === null) {
                $data_points[] = ['x' => $instante($bucket), 'y' => $valor];
            } elseif (isset($valores_x[$bucket])) {
                // Par de leituras do mesmo instante (mesmo bloco de tempo)
                $data_points[] = ['x' => $valores_x[$bucket], 'y' => $valor, 't' => $instante($bucket)];
            }
        }

        $rendered_datasets[] = [
            'id' => $dataset['id'],
            'device_id' => $dataset['device_id'],
            'device_name' => $dataset['device_name'] ?? null,
            'variable_name' => $var_name,
            'alias' => $dataset['alias'] ?? $var_name,
            'color' => $dataset['color'],
            'line_style' => $dataset['line_style'] ?? 'solid',
            'axis' => 'y',
            'data' => $data_points,
            'data_count' => count($data_points)
        ];
    }

    // Nada para desenhar: diz o porquê, para a página não mostrar um quadro em branco
    $aviso = null;
    if (!array_filter($rendered_datasets, fn($ds) => $ds['data_count'] > 0)) {
        $nomes_periodo = ['24h' => 'nas últimas 24 horas', '7d' => 'nos últimos 7 dias', '30d' => 'nos últimos 30 dias'];
        $periodo_txt = (!empty($chart['date_start']) || !empty($chart['date_end'])) ? 'no período escolhido'
            : ($nomes_periodo[$chart['time_range'] ?? 'all'] ?? null);
        foreach ($datasets as $dataset) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $dataset['variable_name'])) {
                continue;
            }
            // A variável existe nas leituras, mas não é número?
            $q = "SELECT COUNT(*) FROM device_payloads WHERE device_id = ? AND JSON_EXTRACT(payload, CONCAT('$.', ?)) IS NOT NULL";
            $qp = [$dataset['device_id'], $dataset['variable_name']];
            if ($range_start !== null) { $q .= " AND created_at >= FROM_UNIXTIME(?)"; $qp[] = $range_start; }
            if ($range_end !== null) { $q .= " AND created_at <= FROM_UNIXTIME(?)"; $qp[] = $range_end; }
            $st = $conn->prepare($q);
            $st->execute($qp);
            if ((int)$st->fetchColumn() > 0 && !$eixo_x) {
                $aviso = "A variável \"{$dataset['variable_name']}\" não tem valores numéricos para desenhar.";
                break;
            }
        }
        if ($aviso === null) {
            if ($eixo_x) {
                $aviso = 'Nenhum par de leituras no mesmo instante entre a variável do eixo X e as do eixo Y'
                    . ($periodo_txt ? " $periodo_txt." : '.');
            } else {
                $aviso = $periodo_txt ? "Sem leituras $periodo_txt." : 'Ainda não há leituras para este gráfico.';
            }
        }
    }

    // 3. Resposta padronizada com o contrato unificado
    http_response_code(200);
    $saida = json_encode([
        'success' => true,
        'chart' => $chart,
        'datasets' => $rendered_datasets,
        'bucket_seconds' => $bucket_seconds,
        'aviso' => $aviso,
        // 'tempo': x é o instante; 'xy': x é o valor da variável abaixo
        'modo' => $eixo_x ? 'xy' : 'tempo',
        'eixo_x' => $eixo_x ? [
            'variable_name' => $eixo_x['variable_name'],
            'alias' => $eixo_x['alias'] ?? $eixo_x['variable_name'],
            'device_name' => $eixo_x['device_name'] ?? null,
        ] : null
    ]);
    // Grava o cache de forma atômica (arquivo temporário + rename) e apaga as
    // versões de antes de uma edição do gráfico
    if ($saida !== false && is_dir($cacheDir)) {
        foreach (glob($cacheDir . '/' . $chart_id . '-*.json') ?: [] as $antigo) {
            if ($antigo !== $cacheArq) {
                @unlink($antigo);
            }
        }
        $tmp = $cacheArq . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $saida) !== false) {
            @rename($tmp, $cacheArq);
        }
    }
    if (!empty($cacheTrava)) {
        flock($cacheTrava, LOCK_UN);
    }
    header('X-Cache: MISS');
    echo $saida;
    exit;

    } catch (PDOException $e) {
        api_error_response('Erro ao obter gráfico', $e, 500);
    }
?>
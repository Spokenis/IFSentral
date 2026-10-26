<?php
/**
 * ChartType.php - Tipos de gráfico aceitos pela coluna charts.chart_type
 *
 * A coluna é enum('line','bar','pie','doughnut','area','scatter'). Versões
 * anteriores dos formulários enviavam os nomes em português ("linha",
 * "barra"), que o MySQL em modo estrito recusa com erro 500 — por isso os
 * dois formatos são aceitos aqui e gravados sempre no formato do enum.
 */

/**
 * Converte o tipo recebido para o valor do enum, ou null se for inválido.
 */
function normalize_chart_type($type) {
    $aliases = [
        'line' => 'line', 'linha' => 'line',
        'bar' => 'bar', 'barra' => 'bar',
        'pie' => 'pie', 'pizza' => 'pie',
        'doughnut' => 'doughnut', 'rosca' => 'doughnut',
        'area' => 'area',
        'scatter' => 'scatter', 'dispersao' => 'scatter',
    ];
    return is_string($type) ? ($aliases[strtolower(trim($type))] ?? null) : null;
}

/**
 * Valida os datasets de um gráfico (limites da tabela chart_datasets) e
 * preenche os campos opcionais com os padrões. Devolve a mensagem de erro,
 * ou null se estiver tudo certo.
 */
function validate_chart_datasets(array $datasets) {
    foreach ($datasets as $ds) {
        if (!is_object($ds) || !isset($ds->device_id) || !is_numeric($ds->device_id)) {
            return 'Cada dataset precisa de um device_id numérico.';
        }
        if (!isset($ds->variable_name) || !is_string($ds->variable_name) || trim($ds->variable_name) === '') {
            return 'Cada dataset precisa de uma variável (variable_name).';
        }
        if (mb_strlen($ds->variable_name) > 100) {
            return 'O nome da variável deve ter no máximo 100 caracteres.';
        }
        if (isset($ds->alias) && (!is_string($ds->alias) || mb_strlen($ds->alias) > 100)) {
            return 'O apelido do dataset deve ter no máximo 100 caracteres.';
        }
        if (isset($ds->color) && $ds->color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$ds->color)) {
            return 'Cor inválida (use o formato #RRGGBB).';
        }
        $ds->line_style = $ds->line_style ?? 'solid';
        $ds->axis = $ds->axis ?? 'y';
        if (!in_array($ds->line_style, ['solid', 'dashed', 'dotted'], true) || !in_array($ds->axis, ['x', 'y'], true)) {
            return 'Estilo de linha ou eixo inválido.';
        }
    }
    return null;
}
?>

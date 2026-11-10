<?php
/**
 * preencher-agregados.php - Copia as leituras antigas para as tabelas dos gráficos
 *
 *   php src/db/preencher-agregados.php
 *   php src/db/preencher-agregados.php --revisar-desde-id 12345
 *       # revisão pós-atualização: copia o que faltar das leituras com id > 12345
 *       # (gravadas pela versão antiga enquanto os contêineres eram trocados)
 *
 * Roda sozinho, em segundo plano, quando um contêiner web inicia (ver
 * docker-entrypoint.sh) e pode ser chamado à mão. É seguro repetir: pula o que
 * já foi copiado e, com dois processos ao mesmo tempo, só um trabalha (trava
 * no MySQL). Ao terminar, os gráficos passam a ler as tabelas novas
 * (AGREGADOS_PRONTOS = 1). Ver src/core/Agregados.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/Agregados.php';

use App\Core\Agregados;

if (!Agregados::tabelasExistem($conn)) {
    fwrite(STDERR, "Tabelas de agregados ausentes: aplique src/db/migracoes/2026-11-08b_agregados_graficos.sql\n");
    exit(1);
}

$i = array_search('--revisar-desde-id', $argv, true);
if ($i !== false) {
    $desde = (int) ($argv[$i + 1] ?? 0);
    $n = Agregados::revisarDesde($conn, $desde);
    printf("Revisão dos agregados: %d leituras examinadas desde o id %d.\n", $n, $desde);
    exit(0);
}

$inicio = microtime(true);
$total = 0;
$ultimoAviso = 0;
while (true) {
    $r = Agregados::preencherLote($conn, 2000);
    if (!empty($r['ocupado'])) {
        echo "Outra cópia já está rodando; nada a fazer aqui.\n";
        exit(0);
    }
    $total += $r['processadas'];
    if ($r['pronto']) {
        break;
    }
    if (microtime(true) - $ultimoAviso > 10) {
        printf("... %d leituras copiadas (até o id %d de %d)\n", $total, $r['cursor'], $r['ate']);
        $ultimoAviso = microtime(true);
    }
}
printf("Agregados prontos: %d leituras copiadas em %.1f s. Os gráficos já usam as tabelas novas.\n",
    $total, microtime(true) - $inicio);

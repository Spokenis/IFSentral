<?php
/**
 * Agregados - Valores numéricos já extraídos, para os gráficos não lerem JSON
 *
 * Os gráficos agregavam device_payloads extraindo cada variável do JSON
 * (JSON_EXTRACT + REGEXP em cada linha): num gráfico de 7 dias com 10
 * dispositivos eram ~100 mil linhas por carregamento, e no teste de carga de
 * 08/11/2026 a CPU do MySQL saturava em ~15 gráficos/s. Agora cada leitura
 * também grava, na mesma transação:
 *
 *   device_payload_values  um valor numérico por variável (gráficos de até 7 dias)
 *   device_payload_hourly  soma/quantidade/mín/máx por hora (gráficos mais longos)
 *
 * Os dados que já existiam são copiados por preencherLote() (linha de comando:
 * src/db/preencher-agregados.php; ou o botão em Administração). Enquanto a cópia
 * não termina, prontos() devolve false e os gráficos continuam no caminho antigo.
 */

namespace App\Core;

class Agregados
{
    private const ARQUIVO_MIGRACAO = __DIR__ . '/../db/migracoes/2026-11-08b_agregados_graficos.sql';

    /**
     * Variáveis numéricas de primeiro nível do payload, como o caminho antigo
     * as enxergava (JSON_EXTRACT de '$.nome' que casa com um número): números e
     * textos numéricos ("25.3"); booleanos e o resto ficam de fora.
     * @return array<string, float>
     */
    public static function extrairNumericos($payload): array
    {
        $saida = [];
        foreach ((array) $payload as $nome => $valor) {
            if (!is_string($nome) || strlen($nome) > 100 || !preg_match('/^[a-zA-Z0-9_]+$/', $nome)) {
                continue;
            }
            if (is_int($valor) || is_float($valor)) {
                if (is_finite((float) $valor)) {
                    $saida[$nome] = (float) $valor;
                }
            } elseif (is_string($valor) && preg_match('/^-?[0-9]+([.][0-9]+)?([eE][-+]?[0-9]+)?$/', $valor)) {
                $saida[$nome] = (float) $valor;
            }
        }
        return $saida;
    }

    /**
     * Grava os valores de uma leitura recém-inserida (chamado por
     * PayloadHandler dentro da mesma transação). O instante vem da própria
     * linha de device_payloads, para ficar idêntico ao do dado.
     */
    public static function gravar(\PDO $conn, int $payloadId, array $valores): void
    {
        if (!$valores) {
            return;
        }
        $linhas = [];
        $params = [];
        foreach ($valores as $nome => $valor) {
            $linhas[] = 'SELECT ? AS variavel, ? AS valor';
            $params[] = $nome;
            $params[] = $valor;
        }
        $v = implode(' UNION ALL ', $linhas);

        $conn->prepare(
            "INSERT IGNORE INTO device_payload_values (device_id, variavel, created_at, payload_id, valor)
             SELECT dp.device_id, v.variavel, dp.created_at, dp.id, v.valor
             FROM device_payloads dp JOIN ($v) v
             WHERE dp.id = ?"
        )->execute(array_merge($params, [$payloadId]));

        $conn->prepare(
            "INSERT INTO device_payload_hourly (device_id, variavel, hora, soma, qtd, minimo, maximo)
             SELECT dp.device_id, v.variavel, FLOOR(UNIX_TIMESTAMP(dp.created_at) / 3600), v.valor, 1, v.valor, v.valor
             FROM device_payloads dp JOIN ($v) v
             WHERE dp.id = ?
             ON DUPLICATE KEY UPDATE soma = soma + VALUES(soma), qtd = qtd + 1,
                 minimo = LEAST(minimo, VALUES(minimo)), maximo = GREATEST(maximo, VALUES(maximo))"
        )->execute(array_merge($params, [$payloadId]));
    }

    /** Os gráficos já podem usar as tabelas novas? (cópia dos dados antigos concluída) */
    public static function prontos(\PDO $conn): bool
    {
        static $cache = null;
        if ($cache === null) {
            try {
                $cache = self::config($conn, 'AGREGADOS_PRONTOS') === '1';
            } catch (\PDOException $e) {
                $cache = false;
            }
        }
        return $cache;
    }

    public static function tabelasExistem(\PDO $conn): bool
    {
        try {
            $conn->query("SELECT 1 FROM device_payload_hourly LIMIT 1");
            $conn->query("SELECT 1 FROM device_payload_values LIMIT 1");
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /** Cria as tabelas a partir do arquivo de migração (no Lite não há linha de comando) */
    public static function criarTabelas(\PDO $conn): bool
    {
        $sql = @file_get_contents(self::ARQUIVO_MIGRACAO);
        if ($sql === false) {
            return false;
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        try {
            foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $comando) {
                $conn->exec($comando);
            }
        } catch (\PDOException $e) {
            return false;
        }
        return self::tabelasExistem($conn);
    }

    /**
     * Copia para as tabelas novas um lote de leituras antigas. Chame até
     * 'pronto' => true. Leituras que já estão lá (gravadas pelo código novo
     * enquanto a cópia rodava) são puladas, para a média por hora não contar
     * duas vezes. Ao terminar, marca AGREGADOS_PRONTOS = 1.
     * @return array{processadas:int, cursor:int, ate:int, pronto:bool}
     */
    public static function preencherLote(\PDO $conn, int $lote = 2000): array
    {
        if (self::config($conn, 'AGREGADOS_PRONTOS') === '1') {
            return ['processadas' => 0, 'cursor' => 0, 'ate' => 0, 'pronto' => true];
        }
        // Uma cópia por vez: os dois contêineres web disparam a cópia ao
        // iniciar, e dois lotes iguais ao mesmo tempo contariam em dobro na
        // média por hora
        if ((int) $conn->query("SELECT GET_LOCK('ifscentral_agregados', 0)")->fetchColumn() !== 1) {
            return ['processadas' => 0, 'cursor' => 0, 'ate' => 0, 'pronto' => false, 'ocupado' => true];
        }
        self::liberarTravaNoFim($conn);
        try {
            return self::copiarLote($conn, $lote);
        } finally {
            $conn->query("SELECT RELEASE_LOCK('ifscentral_agregados')");
        }
    }

    private static function copiarLote(\PDO $conn, int $lote): array
    {
        $ate = self::config($conn, 'AGREGADOS_ATE');
        if ($ate === null || $ate === '') {
            // Até onde copiar: o que existe agora. O que chegar depois já é
            // gravado pelo PayloadHandler.
            $ate = (string) (int) $conn->query("SELECT COALESCE(MAX(id), 0) FROM device_payloads")->fetchColumn();
            self::definir($conn, 'AGREGADOS_ATE', $ate);
        }
        $ate = (int) $ate;
        $cursor = (int) (self::config($conn, 'AGREGADOS_CURSOR') ?? 0);

        $r = self::processarFaixa($conn, $cursor, $ate, $lote, function () use ($conn, &$ultimoId) {
            self::definir($conn, 'AGREGADOS_CURSOR', (string) $ultimoId);
        }, $ultimoId);
        if ($r === null) {
            self::definir($conn, 'AGREGADOS_PRONTOS', '1');
            return ['processadas' => 0, 'cursor' => $cursor, 'ate' => $ate, 'pronto' => true];
        }
        return ['processadas' => $r, 'cursor' => $ultimoId, 'ate' => $ate, 'pronto' => false];
    }

    /**
     * Revisão depois de uma atualização: copia o que faltar das leituras com
     * id > $desdeId (até o último id existente agora), pulando as que já estão
     * nas tabelas. Cobre leituras gravadas pelos contêineres da versão antiga
     * (que não conheciam as tabelas) enquanto o manutencao/atualizar.sh
     * trocava os contêineres. Devolve quantas leituras foram examinadas.
     */
    public static function revisarDesde(\PDO $conn, int $desdeId): int
    {
        if ((int) $conn->query("SELECT GET_LOCK('ifscentral_agregados', 30)")->fetchColumn() !== 1) {
            return 0;
        }
        self::liberarTravaNoFim($conn);
        try {
            $ate = (int) $conn->query("SELECT COALESCE(MAX(id), 0) FROM device_payloads")->fetchColumn();
            $cursor = $desdeId;
            $total = 0;
            while (($n = self::processarFaixa($conn, $cursor, $ate, 2000, null, $ultimoId)) !== null) {
                $total += $n;
                $cursor = $ultimoId;
            }
            return $total;
        } finally {
            $conn->query("SELECT RELEASE_LOCK('ifscentral_agregados')");
        }
    }

    /**
     * Copia as leituras de (cursor, ate], no máximo $lote, numa transação.
     * Devolve quantas linhas examinou (null se não havia nenhuma) e o último id
     * em $ultimoId. $antesDoCommit roda dentro da transação (para gravar o
     * progresso junto com os dados).
     */
    private static function processarFaixa(\PDO $conn, int $cursor, int $ate, int $lote, ?callable $antesDoCommit, &$ultimoId): ?int
    {
        $stmt = $conn->prepare(
            "SELECT id, payload FROM device_payloads WHERE id > ? AND id <= ? ORDER BY id LIMIT " . max(1, $lote)
        );
        $stmt->execute([$cursor, $ate]);
        $linhas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$linhas) {
            return null;
        }

        $primeiro = (int) $linhas[0]['id'];
        $ultimoId = (int) end($linhas)['id'];
        $ja = $conn->prepare("SELECT DISTINCT payload_id FROM device_payload_values WHERE payload_id BETWEEN ? AND ?");
        $ja->execute([$primeiro, $ultimoId]);
        $jaGravadas = array_flip(array_map('intval', $ja->fetchAll(\PDO::FETCH_COLUMN)));

        // (payload_id, variavel, valor) das leituras que faltam
        $faltam = [];
        foreach ($linhas as $l) {
            $id = (int) $l['id'];
            if (isset($jaGravadas[$id])) {
                continue;
            }
            foreach (self::extrairNumericos(json_decode($l['payload'], true) ?? []) as $nome => $valor) {
                $faltam[] = [$id, $nome, $valor];
            }
        }

        $conn->beginTransaction();
        try {
            foreach (array_chunk($faltam, 500) as $parte) {
                $sel = [];
                $params = [];
                foreach ($parte as [$id, $nome, $valor]) {
                    $sel[] = 'SELECT ? AS pid, ? AS variavel, ? AS valor';
                    array_push($params, $id, $nome, $valor);
                }
                $v = implode(' UNION ALL ', $sel);
                $conn->prepare(
                    "INSERT IGNORE INTO device_payload_values (device_id, variavel, created_at, payload_id, valor)
                     SELECT dp.device_id, v.variavel, dp.created_at, dp.id, v.valor
                     FROM ($v) v JOIN device_payloads dp ON dp.id = v.pid"
                )->execute($params);
                $conn->prepare(
                    "INSERT INTO device_payload_hourly (device_id, variavel, hora, soma, qtd, minimo, maximo)
                     SELECT dp.device_id, v.variavel, FLOOR(UNIX_TIMESTAMP(dp.created_at) / 3600) AS h,
                            SUM(v.valor), COUNT(*), MIN(v.valor), MAX(v.valor)
                     FROM ($v) v JOIN device_payloads dp ON dp.id = v.pid
                     GROUP BY dp.device_id, v.variavel, h
                     ON DUPLICATE KEY UPDATE soma = soma + VALUES(soma), qtd = qtd + VALUES(qtd),
                         minimo = LEAST(minimo, VALUES(minimo)), maximo = GREATEST(maximo, VALUES(maximo))"
                )->execute($params);
            }
            if ($antesDoCommit) {
                $antesDoCommit();
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        return count($linhas);
    }

    /**
     * Com conexão persistente, uma trava do MySQL sobrevive ao fim do script:
     * se ele morrer no meio (tempo limite, erro fatal), o finally não roda e a
     * cópia ficaria "ocupada" para sempre. Isto a libera em qualquer caso.
     */
    private static function liberarTravaNoFim(\PDO $conn): void
    {
        static $registrado = false;
        if (!$registrado) {
            $registrado = true;
            register_shutdown_function(function () use ($conn) {
                try {
                    $conn->query("SELECT RELEASE_LOCK('ifscentral_agregados')");
                } catch (\Throwable $e) {
                }
            });
        }
    }

    /** Progresso da cópia, para a tela de administração */
    public static function situacao(\PDO $conn): array
    {
        if (!self::tabelasExistem($conn)) {
            return ['tabelas' => false, 'pronto' => false, 'cursor' => 0, 'ate' => 0];
        }
        $ate = self::config($conn, 'AGREGADOS_ATE');
        return [
            'tabelas' => true,
            'pronto' => self::config($conn, 'AGREGADOS_PRONTOS') === '1',
            'cursor' => (int) (self::config($conn, 'AGREGADOS_CURSOR') ?? 0),
            'ate' => $ate === null || $ate === '' ? (int) $conn->query("SELECT COALESCE(MAX(id), 0) FROM device_payloads")->fetchColumn() : (int) $ate,
        ];
    }

    private static function config(\PDO $conn, string $chave): ?string
    {
        $stmt = $conn->prepare("SELECT setting_value FROM api_settings WHERE setting_key = ?");
        $stmt->execute([$chave]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private static function definir(\PDO $conn, string $chave, string $valor): void
    {
        $conn->prepare(
            "INSERT INTO api_settings (setting_key, setting_value, description, is_editable)
             VALUES (?, ?, 'Gráficos: cópia dos dados para as tabelas de agregados (automático)', 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$chave, $valor]);
    }
}

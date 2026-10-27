<?php
/**
 * aplicar-migracoes.php - Aplica as migrações pendentes de src/db/migracoes/
 *
 *   php src/db/aplicar-migracoes.php
 *
 * Chamado por manutencao/atualizar.sh antes de trocar os contêineres. Aplica,
 * em ordem de nome, os arquivos .sql ainda não registrados na tabela
 * schema_migrations.
 *
 * Durante a atualização a versão antiga e a nova rodam ao mesmo tempo contra o
 * mesmo banco, então as migrações devem só ACRESCENTAR (tabelas, colunas
 * opcionais, índices) — nunca apagar ou renomear o que a versão antiga usa.
 *
 * Uma migração que já tinha sido aplicada à mão, ou que já vem no dump de
 * instalações novas (src/db/ifsentral_bd.sql), falha com "já existe": isso é
 * tratado como aplicada e só registrado.
 */

require_once __DIR__ . '/../config/config.php';

// "Tabela/coluna/índice já existe" e "não dá para apagar o que não existe"
const JA_APLICADA = [1050, 1060, 1061, 1091];

try {
    $conn = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Não foi possível conectar ao banco: {$e->getMessage()}\n");
    exit(1);
}

$conn->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        arquivo varchar(255) NOT NULL,
        aplicada_em timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (arquivo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$aplicadas = $conn->query("SELECT arquivo FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$arquivos = glob(__DIR__ . '/migracoes/*.sql');
sort($arquivos);

$novas = 0;
foreach ($arquivos as $caminho) {
    $arquivo = basename($caminho);
    if (in_array($arquivo, $aplicadas, true)) {
        continue;
    }

    // Tira os comentários de linha e separa os comandos pelo ";" no fim da linha
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($caminho));
    $comandos = array_filter(array_map('trim', preg_split('/;\s*$/m', $sql)));

    foreach ($comandos as $comando) {
        try {
            $conn->exec($comando);
        } catch (PDOException $e) {
            $codigo = $e->errorInfo[1] ?? null;
            if (!in_array($codigo, JA_APLICADA, true)) {
                fwrite(STDERR, "Migração $arquivo falhou: {$e->getMessage()}\n");
                exit(1);
            }
            echo "  $arquivo: já estava aplicado ({$e->errorInfo[2]})\n";
        }
    }

    $conn->prepare("INSERT INTO schema_migrations (arquivo) VALUES (?)")->execute([$arquivo]);
    echo "Migração aplicada: $arquivo\n";
    $novas++;
}

echo $novas ? "$novas migração(ões) aplicada(s).\n" : "Banco já está atualizado.\n";

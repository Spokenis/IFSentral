<?php
/**
 * Documentacao - Seções da página /documentacao, editáveis pelo administrador
 *
 * As seções ficam na tabela documentation_sections. Na primeira vez em que a
 * página é aberta (tabela vazia), o conteúdo padrão de
 * src/docs/documentacao-padrao.html é copiado para lá. Cada edição guarda a
 * versão anterior em documentation_revisions, para poder voltar atrás.
 *
 * Se as tabelas ainda não existem, criarTabelas() tenta criá-las (o Lite roda
 * em servidores onde não dá para aplicar migração pela linha de comando); se
 * o usuário do banco não tiver permissão, a página mostra o conteúdo padrão
 * direto do arquivo, só para leitura.
 */

namespace App\Core;

require_once __DIR__ . '/HtmlSanitizer.php';

class Documentacao
{
    public const CORES = ['primary', 'secondary', 'success', 'info', 'warning', 'danger'];
    private const ARQUIVO_PADRAO = __DIR__ . '/../docs/documentacao-padrao.html';
    private const ARQUIVO_MIGRACAO = __DIR__ . '/../db/migracoes/2026-11-08_documentacao_editavel.sql';
    private const MAX_REVISOES = 20;

    private \PDO $conn;

    public function __construct(\PDO $conn)
    {
        $this->conn = $conn;
    }

    /** As tabelas existem? (false enquanto a migração não foi aplicada) */
    public function disponivel(): bool
    {
        try {
            $this->conn->query("SELECT 1 FROM documentation_sections LIMIT 1");
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Cria as tabelas a partir do arquivo de migração. Devolve false se o
     * usuário do banco não tem permissão (aí a migração precisa ser
     * importada à mão, pelo phpMyAdmin).
     */
    public function criarTabelas(): bool
    {
        $sql = @file_get_contents(self::ARQUIVO_MIGRACAO);
        if ($sql === false) {
            return false;
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        try {
            foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $comando) {
                $this->conn->exec($comando);
            }
        } catch (\PDOException $e) {
            return false;
        }
        return $this->disponivel();
    }

    /**
     * Seções em ordem. Sem as tabelas, devolve as do arquivo padrão.
     * @return array<int, array<string, mixed>>
     */
    public function listar(bool $incluirOcultas = false): array
    {
        if (!$this->disponivel()) {
            return self::secoesPadrao();
        }
        $this->garantirConteudoInicial();

        $sql = "SELECT id, slug, title, icon, color, outline, content, position, visible, updatedAt
                FROM documentation_sections"
             . ($incluirOcultas ? '' : ' WHERE visible = 1')
             . " ORDER BY position, id";
        return $this->conn->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function obter(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM documentation_sections WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Cria (sem id) ou atualiza uma seção. Devolve o id.
     * @throws \InvalidArgumentException com mensagem para o usuário
     */
    public function salvar(array $dados, int $usuarioId): int
    {
        $titulo = trim((string) ($dados['title'] ?? ''));
        if ($titulo === '' || mb_strlen($titulo) > 150) {
            throw new \InvalidArgumentException('O título é obrigatório e deve ter no máximo 150 caracteres.');
        }
        $icone = trim((string) ($dados['icon'] ?? ''));
        if ($icone !== '' && !preg_match('/^[a-z0-9 -]{1,60}$/', $icone)) {
            throw new \InvalidArgumentException('Ícone inválido. Use classes do Font Awesome, ex.: fas fa-key');
        }
        $cor = (string) ($dados['color'] ?? 'primary');
        if (!in_array($cor, self::CORES, true)) {
            $cor = 'primary';
        }
        $contorno = !empty($dados['outline']) ? 1 : 0;
        $conteudo = HtmlSanitizer::limpar((string) ($dados['content'] ?? ''));
        if (strlen($conteudo) > 1000000) {
            throw new \InvalidArgumentException('O conteúdo passou de 1 MB. Imagens coladas no texto ocupam muito espaço: prefira links.');
        }

        $id = isset($dados['id']) ? (int) $dados['id'] : 0;
        if ($id > 0) {
            $atual = $this->obter($id);
            if (!$atual) {
                throw new \InvalidArgumentException('Seção não encontrada.');
            }
            $this->conn->beginTransaction();
            try {
                if ($atual['content'] !== $conteudo || $atual['title'] !== $titulo) {
                    $this->guardarRevisao($atual, $usuarioId);
                }
                $stmt = $this->conn->prepare(
                    "UPDATE documentation_sections
                     SET title = ?, icon = ?, color = ?, outline = ?, content = ?, updated_by = ?
                     WHERE id = ?"
                );
                $stmt->execute([$titulo, $icone, $cor, $contorno, $conteudo, $usuarioId, $id]);
                $this->conn->commit();
            } catch (\Throwable $e) {
                $this->conn->rollBack();
                throw $e;
            }
            return $id;
        }

        $slug = $this->slugLivre($titulo);
        $posicao = (int) $this->conn->query("SELECT COALESCE(MAX(position), 0) + 10 FROM documentation_sections")->fetchColumn();
        $stmt = $this->conn->prepare(
            "INSERT INTO documentation_sections (slug, title, icon, color, outline, content, position, visible, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)"
        );
        $stmt->execute([$slug, $titulo, $icone, $cor, $contorno, $conteudo, $posicao, $usuarioId]);
        return (int) $this->conn->lastInsertId();
    }

    public function excluir(int $id): void
    {
        $stmt = $this->conn->prepare("DELETE FROM documentation_sections WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function definirVisivel(int $id, bool $visivel): void
    {
        $stmt = $this->conn->prepare("UPDATE documentation_sections SET visible = ? WHERE id = ?");
        $stmt->execute([$visivel ? 1 : 0, $id]);
    }

    /** Sobe (-1) ou desce (+1) a seção uma posição */
    public function mover(int $id, int $direcao): void
    {
        $ids = $this->conn->query("SELECT id FROM documentation_sections ORDER BY position, id")->fetchAll(\PDO::FETCH_COLUMN);
        $ids = array_map('intval', $ids);
        $i = array_search($id, $ids, true);
        $j = $i === false ? false : $i + ($direcao < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($ids)) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];

        // Renumera tudo (10, 20, 30...): posições repetidas não deixam trocar
        $stmt = $this->conn->prepare("UPDATE documentation_sections SET position = ? WHERE id = ?");
        $this->conn->beginTransaction();
        foreach ($ids as $k => $secaoId) {
            $stmt->execute([($k + 1) * 10, $secaoId]);
        }
        $this->conn->commit();
    }

    /** @return array<int, array<string, mixed>> versões anteriores, da mais nova para a mais antiga */
    public function revisoes(int $secaoId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT r.id, r.title, r.createdAt, u.name AS edited_by_name
             FROM documentation_revisions r
             LEFT JOIN users u ON u.id = r.edited_by
             WHERE r.section_id = ?
             ORDER BY r.id DESC"
        );
        $stmt->execute([$secaoId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function obterRevisao(int $secaoId, int $revisaoId): ?array
    {
        $stmt = $this->conn->prepare("SELECT id, title, content, createdAt FROM documentation_revisions WHERE id = ? AND section_id = ?");
        $stmt->execute([$revisaoId, $secaoId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Conteúdo original (do arquivo padrão) de uma seção que veio de lá */
    public static function padraoDe(string $slug): ?array
    {
        foreach (self::secoesPadrao() as $secao) {
            if ($secao['slug'] === $slug) {
                return $secao;
            }
        }
        return null;
    }

    /** Troca os marcadores do conteúdo pelos valores desta instalação */
    public static function aplicarMarcadores(string $html): string
    {
        if (function_exists('app_absolute_url')) {
            // Lite: considera subpasta, APP_URL e servidor sem mod_rewrite
            $base = rtrim(app_absolute_url(''), '/');
        } else {
            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
            $base = $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        // A forma codificada aparece quando o marcador está dentro de um href
        return str_ireplace(['{{URL_BASE}}', '%7B%7BURL_BASE%7D%7D'], htmlspecialchars($base, ENT_QUOTES), $html);
    }

    // ------------------------------------------------------------------

    /** Copia o conteúdo padrão para a tabela quando ela está vazia */
    private function garantirConteudoInicial(): void
    {
        $total = (int) $this->conn->query("SELECT COUNT(*) FROM documentation_sections")->fetchColumn();
        if ($total > 0) {
            return;
        }
        // INSERT IGNORE: duas páginas abertas ao mesmo tempo não duplicam
        // (o slug é único)
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO documentation_sections (slug, title, icon, color, outline, content, position, visible)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
        );
        foreach (self::secoesPadrao() as $secao) {
            $stmt->execute([$secao['slug'], $secao['title'], $secao['icon'], $secao['color'], $secao['outline'], $secao['content'], $secao['position']]);
        }
    }

    private function guardarRevisao(array $atual, int $usuarioId): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO documentation_revisions (section_id, title, content, edited_by) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$atual['id'], $atual['title'], $atual['content'], $usuarioId]);

        // Mantém só as últimas versões de cada seção
        $stmt = $this->conn->prepare(
            "SELECT id FROM documentation_revisions WHERE section_id = ? ORDER BY id DESC LIMIT 1 OFFSET " . self::MAX_REVISOES
        );
        $stmt->execute([$atual['id']]);
        $limite = $stmt->fetchColumn();
        if ($limite) {
            $del = $this->conn->prepare("DELETE FROM documentation_revisions WHERE section_id = ? AND id <= ?");
            $del->execute([$atual['id'], $limite]);
        }
    }

    private function slugLivre(string $titulo): string
    {
        $base = strtolower((string) @iconv('UTF-8', 'ASCII//TRANSLIT', $titulo));
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        $base = substr($base !== '' ? $base : 'secao', 0, 60);
        $slug = $base;
        $stmt = $this->conn->prepare("SELECT 1 FROM documentation_sections WHERE slug = ?");
        for ($n = 2; ; $n++) {
            $stmt->execute([$slug]);
            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $n;
        }
    }

    /**
     * Seções do arquivo padrão. Lido com expressão regular (e não com DOM)
     * porque o formato do arquivo é fixo e assim a página abre mesmo em
     * servidor sem a extensão dom.
     * @return array<int, array<string, mixed>>
     */
    private static function secoesPadrao(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $html = @file_get_contents(self::ARQUIVO_PADRAO);
        if ($html === false) {
            return $cache;
        }
        preg_match_all('#<section ([^>]*)>(.*?)</section>#s', $html, $secoes, PREG_SET_ORDER);
        $posicao = 0;
        foreach ($secoes as [, $atributos, $conteudo]) {
            preg_match_all('/data-([a-z]+)="([^"]*)"/', $atributos, $pares, PREG_SET_ORDER);
            $a = [];
            foreach ($pares as [, $nome, $valor]) {
                $a[$nome] = html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (empty($a['slug'])) {
                continue;
            }
            $posicao += 10;
            $cor = $a['cor'] ?? 'primary';
            $cache[] = [
                'id' => 0,
                'slug' => $a['slug'],
                'title' => $a['titulo'] ?? $a['slug'],
                'icon' => $a['icone'] ?? '',
                'color' => in_array($cor, self::CORES, true) ? $cor : 'primary',
                'outline' => ($a['contorno'] ?? '0') === '1' ? 1 : 0,
                'content' => trim($conteudo),
                'position' => $posicao,
                'visible' => 1,
                'updatedAt' => null,
            ];
        }
        return $cache;
    }
}

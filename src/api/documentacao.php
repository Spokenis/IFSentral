<?php
/**
 * api/documentacao.php - Edição da documentação pelo administrador
 *
 * GET  ?id=N                     -> seção completa (para abrir no editor)
 * GET  ?id=N&revisoes=1          -> lista das versões anteriores
 * GET  ?id=N&revisao=R           -> conteúdo de uma versão anterior
 * GET  ?id=N&padrao=1            -> conteúdo original (arquivo padrão), se a seção veio de lá
 * POST {acao: "salvar", id?, title, icon, color, outline, content}
 * POST {acao: "excluir" | "subir" | "descer" | "ocultar" | "mostrar", id}
 *
 * Só para o perfil Admin. POST exige token CSRF.
 */

require_once '../config/config.php';
setupSecureCORS();
require_once '../core/ApiError.php';
require_once '../core/Csrf.php';
require_once '../core/Documentacao.php';

use App\Core\Csrf;
use App\Core\Documentacao;

header("Content-Type: application/json; charset=UTF-8");

require '../config/db.php';
require '../auth/auth_check.php';

function responder(int $codigo, array $dados): void
{
    http_response_code($codigo);
    echo json_encode($dados);
    exit;
}

$stmt = $conn->prepare("SELECT id, profile FROM users WHERE id = ? AND deletedAt IS NULL");
$stmt->execute([$_SESSION['user_id'] ?? 0]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$usuario || $usuario['profile'] !== 'Admin') {
    responder(403, ['error' => 'Apenas administradores podem editar a documentação.']);
}
$usuarioId = (int) $usuario['id'];

$docs = new Documentacao($conn);
if (!$docs->disponivel() && !$docs->criarTabelas()) {
    responder(503, ['error' => 'As tabelas da documentação ainda não existem. Aplique a migração 2026-11-08_documentacao_editavel.sql (php src/db/aplicar-migracoes.php).']);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        $secao = $docs->obter($id);
        if (!$secao) {
            responder(404, ['error' => 'Seção não encontrada.']);
        }
        if (isset($_GET['revisoes'])) {
            responder(200, ['revisoes' => $docs->revisoes($id)]);
        }
        if (isset($_GET['revisao'])) {
            $rev = $docs->obterRevisao($id, (int) $_GET['revisao']);
            $rev ? responder(200, $rev) : responder(404, ['error' => 'Versão não encontrada.']);
        }
        if (isset($_GET['padrao'])) {
            $padrao = Documentacao::padraoDe($secao['slug']);
            $padrao ? responder(200, $padrao) : responder(404, ['error' => 'Esta seção foi criada na plataforma e não tem versão padrão.']);
        }
        $secao['tem_padrao'] = Documentacao::padraoDe($secao['slug']) !== null;
        responder(200, $secao);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder(405, ['error' => 'Método não permitido.']);
    }

    Csrf::requireValidToken();

    $raw = file_get_contents('php://input');
    if (strlen($raw) > 2000000) {
        responder(413, ['error' => 'Conteúdo grande demais (máximo de 2 MB por envio).']);
    }
    $dados = json_decode($raw, true);
    if (!is_array($dados)) {
        responder(400, ['error' => 'JSON inválido.']);
    }

    $acao = $dados['acao'] ?? '';
    $id = (int) ($dados['id'] ?? 0);

    if ($acao === 'salvar') {
        try {
            $novoId = $docs->salvar($dados, $usuarioId);
        } catch (InvalidArgumentException | RuntimeException $e) {
            responder(400, ['error' => $e->getMessage()]);
        }
        responder(200, ['success' => true, 'id' => $novoId]);
    }

    if (!$docs->obter($id)) {
        responder(404, ['error' => 'Seção não encontrada.']);
    }

    switch ($acao) {
        case 'excluir':
            $docs->excluir($id);
            break;
        case 'subir':
        case 'descer':
            $docs->mover($id, $acao === 'subir' ? -1 : 1);
            break;
        case 'ocultar':
        case 'mostrar':
            $docs->definirVisivel($id, $acao === 'mostrar');
            break;
        default:
            responder(400, ['error' => 'Ação desconhecida.']);
    }
    responder(200, ['success' => true]);

} catch (PDOException $e) {
    api_error_response('Erro ao salvar a documentação', $e, 500);
}

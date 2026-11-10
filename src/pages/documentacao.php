<?php
require '../auth/auth_check.php';
require '../config/db.php';
require_once __DIR__ . '/../core/Documentacao.php';

use App\Core\Documentacao;

// O perfil vem do banco (não da sessão): quem foi rebaixado deixa de ver o
// editor na hora. A API confere de novo antes de gravar.
$stmtPerfil = $conn->prepare("SELECT profile FROM users WHERE id = ? AND deletedAt IS NULL");
$stmtPerfil->execute([$_SESSION['user_id'] ?? 0]);
$ehAdmin = $stmtPerfil->fetchColumn() === 'Admin';

$docs = new Documentacao($conn);
// Sem as tabelas, a visita de um admin tenta criá-las (no Lite não há como
// aplicar migração pela linha de comando)
$edicaoDisponivel = $ehAdmin && ($docs->disponivel() || $docs->criarTabelas());
$secoes = $docs->listar($edicaoDisponivel);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Documentação | IFSCentral</title>

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
    pre {
        background-color: #f4f4f4;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
        white-space: pre-wrap;
        word-wrap: break-word;
    }
    kbd {
        font-size: 0.85em;
        font-weight: 600;
    }
    .tab-content > .tab-pane > pre {
        margin: 0;
        border-radius: 0 0 4px 4px;
        border-top: none;
    }
    .nav-tabs-container {
        border: 1px solid #ddd;
        border-bottom: none;
        border-radius: 4px 4px 0 0;
    }
    .callout-warning {
        background-color: #fdf3e8 !important;
        border-left-color: #f59e0b !important;
    }
    
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

    /* Documentação editável */
    .secao-doc { scroll-margin-top: 70px; }
    .secao-doc .acoes-secao { display: none; }
    body.modo-edicao .secao-doc .acoes-secao { display: inline-flex; gap: 4px; }
    .secao-doc.secao-oculta { display: none; }
    body.modo-edicao .secao-doc.secao-oculta { display: block; opacity: .55; }
    .acoes-secao .btn { padding: 1px 7px; }
    .barra-edicao { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .indice-doc a { display: inline-block; margin: 0 14px 6px 0; }
    #modal-secao .note-editor { margin-bottom: 0; }
    #modal-secao .note-editable { min-height: 380px; }
    .previa-icone { width: 38px; text-align: center; }
  </style>
<?php if ($edicaoDisponivel): ?>
  <link rel="stylesheet" href="/assets/vendor/summernote/0.8.20/summernote-bs4.min.css">
<?php endif; ?>
</head>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <?php require_once __DIR__ . '/../includes/header.php'; ?>

  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <div class="row mb-2 align-items-center">
          <div class="col-md-8">
            <h1>Documentação</h1>
            <p class="text-muted mb-0">Como usar a plataforma: navegação, permissões, convites — e a documentação técnica da API de dispositivos.</p>
          </div>
<?php if ($edicaoDisponivel): ?>
          <div class="col-md-4 text-md-right mt-2 mt-md-0">
            <div class="barra-edicao justify-content-md-end">
              <button type="button" class="btn btn-outline-secondary" id="btn-modo-edicao"><i class="fas fa-pen mr-1"></i>Editar documentação</button>
              <button type="button" class="btn btn-primary d-none" id="btn-nova-secao"><i class="fas fa-plus mr-1"></i>Nova seção</button>
            </div>
          </div>
<?php elseif ($ehAdmin): ?>
          <div class="col-md-4 mt-2 mt-md-0">
            <div class="alert alert-warning mb-0 small">Para editar, importe <code>src/db/migracoes/2026-11-08_documentacao_editavel.sql</code> no banco (o usuário do banco não tem permissão para criar as tabelas sozinho).</div>
          </div>
<?php endif; ?>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="row">
          <div class="col-md-12">

            <div class="card card-outline card-secondary">
              <div class="card-body py-3 indice-doc">
                <strong class="mr-2"><i class="fas fa-list mr-1"></i>Nesta página:</strong>
<?php foreach ($secoes as $secao): ?>
                <a href="#<?= htmlspecialchars($secao['slug']) ?>" class="<?= $secao['visible'] ? '' : 'secao-oculta-link d-none' ?>"><?= htmlspecialchars($secao['title']) ?></a>
<?php endforeach; ?>
              </div>
            </div>

<?php if (!$secoes): ?>
            <div class="alert alert-info">A documentação ainda não tem seções.</div>
<?php endif; ?>
<?php foreach ($secoes as $i => $secao):
    $classeCard = 'card-' . $secao['color'] . ($secao['outline'] ? ' card-outline' : '');
?>
            <div class="card <?= $classeCard ?> secao-doc<?= $secao['visible'] ? '' : ' secao-oculta' ?>" id="<?= htmlspecialchars($secao['slug']) ?>" data-id="<?= (int) $secao['id'] ?>">
              <div class="card-header">
                <h3 class="card-title"><?php if ($secao['icon']): ?><i class="<?= htmlspecialchars($secao['icon']) ?> mr-1"></i> <?php endif; ?><?= htmlspecialchars($secao['title']) ?><?php if (!$secao['visible']): ?> <span class="badge badge-dark ml-1">Oculta</span><?php endif; ?></h3>
<?php if ($edicaoDisponivel): ?>
                <div class="card-tools acoes-secao">
                  <button type="button" class="btn btn-light btn-sm" data-acao="editar" title="Editar"><i class="fas fa-pen"></i></button>
                  <button type="button" class="btn btn-light btn-sm" data-acao="subir" title="Subir"<?= $i === 0 ? ' disabled' : '' ?>><i class="fas fa-arrow-up"></i></button>
                  <button type="button" class="btn btn-light btn-sm" data-acao="descer" title="Descer"<?= $i === count($secoes) - 1 ? ' disabled' : '' ?>><i class="fas fa-arrow-down"></i></button>
                  <button type="button" class="btn btn-light btn-sm" data-acao="<?= $secao['visible'] ? 'ocultar' : 'mostrar' ?>" title="<?= $secao['visible'] ? 'Ocultar dos usuários' : 'Mostrar aos usuários' ?>"><i class="fas <?= $secao['visible'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                  <button type="button" class="btn btn-light btn-sm text-danger" data-acao="excluir" title="Excluir"><i class="fas fa-trash"></i></button>
                </div>
<?php endif; ?>
              </div>
              <div class="card-body">
<?= Documentacao::aplicarMarcadores($secao['content']) ?>

              </div>
            </div>

<?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>
  </div>

<?php if ($edicaoDisponivel): ?>
  <div class="modal fade" id="modal-secao" tabindex="-1" role="dialog" aria-labelledby="modal-secao-titulo" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modal-secao-titulo">Editar seção</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="secao-id">
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="secao-titulo">Título</label>
              <input type="text" class="form-control" id="secao-titulo" maxlength="150" required>
            </div>
            <div class="form-group col-md-3">
              <label for="secao-icone">Ícone <small class="text-muted">(<a href="https://fontawesome.com/v5/search?m=free" target="_blank" rel="noopener noreferrer">Font Awesome 5</a>)</small></label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text previa-icone"><i id="previa-icone"></i></span></div>
                <input type="text" class="form-control" id="secao-icone" maxlength="60" placeholder="fas fa-key">
              </div>
            </div>
            <div class="form-group col-md-2">
              <label for="secao-cor">Cor</label>
              <select class="form-control" id="secao-cor">
                <option value="primary">Verde</option>
                <option value="success">Verde-claro</option>
                <option value="info">Azul</option>
                <option value="warning">Amarelo</option>
                <option value="danger">Vermelho</option>
                <option value="secondary">Cinza</option>
              </select>
            </div>
            <div class="form-group col-md-1 d-flex align-items-end">
              <div class="custom-control custom-checkbox mb-2" title="Só a borda de cima colorida">
                <input type="checkbox" class="custom-control-input" id="secao-contorno">
                <label class="custom-control-label" for="secao-contorno">Borda</label>
              </div>
            </div>
          </div>
          <textarea id="secao-conteudo"></textarea>
          <small class="text-muted d-block mt-2">
            Use o botão <i class="fas fa-code"></i> para editar o HTML direto (abas, tabelas e blocos de código).
            <code>{{URL_BASE}}</code> vira o endereço do site na página. Scripts e formulários são removidos ao salvar.
          </small>
          <div id="secao-erro" class="alert alert-danger mt-3 mb-0 d-none"></div>
        </div>
        <div class="modal-footer justify-content-between">
          <div class="form-inline" id="bloco-versoes">
            <select class="form-control form-control-sm mr-2" id="secao-versoes">
              <option value="">Versões anteriores…</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-restaurar-padrao"><i class="fas fa-undo mr-1"></i>Texto original</button>
          </div>
          <div>
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-salvar-secao"><i class="fas fa-save mr-1"></i>Salvar</button>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>

</div>

<script src="/assets/vendor/jquery/3.7.1/jquery.min.js"></script>
<script src="/assets/vendor/bootstrap/4.6.2/bootstrap.bundle.min.js"></script>
<script src="/assets/vendor/admin-lte/3.2.0/js/adminlte.min.js"></script>
<script src="/assets/js/fetch-helpers.js"></script>
<script src="/assets/js/profile-picture-helper.js"></script>
<?php if ($edicaoDisponivel): ?>
<script src="/assets/vendor/summernote/0.8.20/summernote-bs4.min.js"></script>
<script src="/assets/vendor/summernote/0.8.20/lang/summernote-pt-BR.min.js"></script>
<script>window.DOC_API = '/api/documentacao';</script>
<script src="/assets/js/documentacao-editor.js"></script>
<?php endif; ?>

</body>
</html>

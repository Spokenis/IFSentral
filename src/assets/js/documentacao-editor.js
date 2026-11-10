// Edição da documentação pelo administrador (carregado só para Admin em
// /documentacao). A API (/api/documentacao) confere o perfil de novo.
(function () {
  const API = window.DOC_API || '/api/documentacao';
  const $modal = $('#modal-secao');
  const $editor = $('#secao-conteudo');
  const campo = id => document.getElementById(id);
  let editorPronto = false;
  let secaoAtual = null;

  // ----- modo de edição (lembrado durante a sessão do navegador) -----
  // Enquanto nada foi alterado, o botão de sair diz "Cancelar" (para quem
  // clicou em editar sem querer); depois da primeira alteração, "Concluir
  // edição". Cada alteração já é gravada na hora: sair não desfaz nada.
  function lerSessao(chave) { try { return sessionStorage.getItem(chave); } catch (e) { return null; } }
  function gravarSessao(chave, valor) { try { sessionStorage.setItem(chave, valor); } catch (e) { /* sem storage */ } }

  function definirModoEdicao(ativo) {
    document.body.classList.toggle('modo-edicao', ativo);
    campo('btn-nova-secao').classList.toggle('d-none', !ativo);
    document.querySelectorAll('.secao-oculta-link').forEach(a => a.classList.toggle('d-none', !ativo));
    const alterou = lerSessao('docAlterou') === '1';
    const btn = campo('btn-modo-edicao');
    if (!ativo) {
      btn.innerHTML = '<i class="fas fa-pen mr-1"></i>Editar documentação';
    } else if (alterou) {
      btn.innerHTML = '<i class="fas fa-check mr-1"></i>Concluir edição';
    } else {
      btn.innerHTML = '<i class="fas fa-times mr-1"></i>Cancelar';
    }
    btn.title = ativo ? 'Sair do modo de edição' : '';
    btn.classList.toggle('btn-outline-secondary', !ativo || !alterou);
    btn.classList.toggle('btn-success', ativo && alterou);
    gravarSessao('docModoEdicao', ativo ? '1' : '');
  }

  campo('btn-modo-edicao').addEventListener('click', () => {
    const entrar = !document.body.classList.contains('modo-edicao');
    if (entrar) gravarSessao('docAlterou', '');
    definirModoEdicao(entrar);
  });
  if (lerSessao('docModoEdicao') === '1') definirModoEdicao(true);

  // ----- editor -----
  function iniciarEditor() {
    if (editorPronto) return;
    $editor.summernote({
      lang: 'pt-BR',
      height: 420,
      dialogsInBody: true,
      toolbar: [
        ['estilo', ['style']],
        ['fonte', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
        ['cor', ['color']],
        ['paragrafo', ['ul', 'ol', 'paragraph']],
        ['inserir', ['table', 'link', 'hr']],
        ['ver', ['codeview', 'fullscreen', 'help']],
      ],
      styleTags: ['p', 'h4', 'h5', 'h6', 'blockquote', 'pre'],
    });
    editorPronto = true;
  }

  function conteudoDoEditor() {
    // Em modo código, o texto do textarea de código ainda não foi passado ao editor
    if ($editor.summernote('codeview.isActivated')) {
      $editor.summernote('codeview.deactivate');
    }
    return $editor.summernote('code');
  }

  function mostrarErro(msg) {
    const el = campo('secao-erro');
    el.textContent = msg || '';
    el.classList.toggle('d-none', !msg);
  }

  function atualizarPreviaIcone() {
    campo('previa-icone').className = campo('secao-icone').value.trim();
  }
  campo('secao-icone').addEventListener('input', atualizarPreviaIcone);

  async function carregarVersoes(id) {
    const sel = campo('secao-versoes');
    sel.innerHTML = '<option value="">Versões anteriores…</option>';
    if (!id) return;
    try {
      const r = await safeJson(await fetch(`${API}?id=${id}&revisoes=1`, { credentials: 'include' }));
      r.revisoes.forEach(v => {
        const quando = new Date(v.createdAt.replace(' ', 'T')).toLocaleString('pt-BR');
        const opt = document.createElement('option');
        opt.value = v.id;
        opt.textContent = `${quando}${v.edited_by_name ? ' — antes da edição de ' + v.edited_by_name : ''}`;
        sel.appendChild(opt);
      });
      sel.disabled = r.revisoes.length === 0;
      if (r.revisoes.length === 0) sel.options[0].textContent = 'Nenhuma versão anterior';
    } catch (e) {
      sel.disabled = true;
    }
  }

  async function abrirEditor(id) {
    iniciarEditor();
    mostrarErro('');
    secaoAtual = null;
    if (id) {
      try {
        secaoAtual = await safeJson(await fetch(`${API}?id=${id}`, { credentials: 'include' }));
      } catch (e) {
        await ifsAlerta('Não foi possível abrir a seção: ' + e.message, { tipo: 'erro' });
        return;
      }
    }
    const s = secaoAtual || { id: '', title: '', icon: 'fas fa-book', color: 'primary', outline: 1, content: '<p></p>', tem_padrao: false };
    campo('modal-secao-titulo').textContent = id ? 'Editar seção' : 'Nova seção';
    campo('secao-id').value = s.id;
    campo('secao-titulo').value = s.title;
    campo('secao-icone').value = s.icon;
    campo('secao-cor').value = s.color;
    campo('secao-contorno').checked = Number(s.outline) === 1;
    atualizarPreviaIcone();
    if ($editor.summernote('codeview.isActivated')) $editor.summernote('codeview.deactivate');
    $editor.summernote('code', s.content);
    campo('bloco-versoes').classList.toggle('invisible', !id);
    campo('btn-restaurar-padrao').classList.toggle('d-none', !s.tem_padrao);
    carregarVersoes(id);
    $modal.modal('show');
  }

  campo('btn-nova-secao').addEventListener('click', () => abrirEditor(null));

  // Carrega uma versão anterior no editor (só grava ao clicar em Salvar)
  campo('secao-versoes').addEventListener('change', async function () {
    if (!this.value) return;
    try {
      const v = await safeJson(await fetch(`${API}?id=${campo('secao-id').value}&revisao=${this.value}`, { credentials: 'include' }));
      campo('secao-titulo').value = v.title;
      $editor.summernote('code', v.content);
      mostrarErro('');
    } catch (e) {
      mostrarErro(e.message);
    }
  });

  campo('btn-restaurar-padrao').addEventListener('click', async () => {
    if (!(await ifsConfirmar('Carregar no editor o texto original desta seção? Ele só substitui o atual quando você clicar em Salvar.', { confirmar: 'Carregar' }))) return;
    try {
      const p = await safeJson(await fetch(`${API}?id=${campo('secao-id').value}&padrao=1`, { credentials: 'include' }));
      campo('secao-titulo').value = p.title;
      campo('secao-icone').value = p.icon;
      campo('secao-cor').value = p.color;
      campo('secao-contorno').checked = Number(p.outline) === 1;
      atualizarPreviaIcone();
      $editor.summernote('code', p.content);
      mostrarErro('');
    } catch (e) {
      mostrarErro(e.message);
    }
  });

  campo('btn-salvar-secao').addEventListener('click', async function () {
    const btn = this;
    const dados = {
      acao: 'salvar',
      id: campo('secao-id').value || undefined,
      title: campo('secao-titulo').value.trim(),
      icon: campo('secao-icone').value.trim(),
      color: campo('secao-cor').value,
      outline: campo('secao-contorno').checked ? 1 : 0,
      content: conteudoDoEditor(),
    };
    if (!dados.title) {
      mostrarErro('Preencha o título.');
      return;
    }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Salvando...';
    try {
      const r = await safeJson(await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(dados),
      }));
      recarregarEm(r.id);
    } catch (e) {
      mostrarErro(e.message);
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-save mr-1"></i>Salvar';
    }
  });

  // Recarrega a página e volta para a seção (a âncora é o slug, que está no id do card)
  function recarregarEm(id) {
    gravarSessao('docAlterou', '1');
    const card = document.querySelector(`.secao-doc[data-id="${id}"]`);
    try { sessionStorage.setItem('docRolarPara', String(id)); } catch (e) { /* sem storage */ }
    if (card) history.replaceState(null, '', '#' + card.id);
    location.reload();
  }
  try {
    const alvo = sessionStorage.getItem('docRolarPara');
    if (alvo) {
      sessionStorage.removeItem('docRolarPara');
      const card = document.querySelector(`.secao-doc[data-id="${alvo}"]`);
      if (card) card.scrollIntoView();
    }
  } catch (e) { /* sem storage */ }

  // ----- botões de cada seção -----
  document.querySelectorAll('.secao-doc .acoes-secao').forEach(barra => {
    barra.addEventListener('click', async e => {
      const btn = e.target.closest('button[data-acao]');
      if (!btn) return;
      const card = btn.closest('.secao-doc');
      const id = Number(card.dataset.id);
      const acao = btn.dataset.acao;
      if (acao === 'editar') {
        abrirEditor(id);
        return;
      }
      if (acao === 'excluir') {
        const titulo = card.querySelector('.card-title').textContent.trim();
        if (!(await ifsConfirmar(`Excluir a seção "${titulo}"? As versões anteriores dela também serão apagadas. Para só tirar da página, use o botão de ocultar.`, { confirmar: 'Excluir', perigo: true }))) return;
      }
      btn.disabled = true;
      try {
        await safeJson(await fetch(API, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ acao, id }),
        }));
        recarregarEm(acao === 'excluir' ? 0 : id);
      } catch (err) {
        await ifsAlerta('Erro: ' + err.message, { tipo: 'erro' });
        btn.disabled = false;
      }
    });
  });
})();

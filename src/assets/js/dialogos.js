// Diálogos da plataforma, no lugar de alert(), confirm() e prompt() do navegador.
//
//   await ifsAlerta('Gráfico salvo!', { tipo: 'sucesso' });
//   if (!(await ifsConfirmar('Excluir o dispositivo?', { perigo: true, confirmar: 'Excluir' }))) return;
//   const motivo = await ifsPerguntar('Por que você precisa criar projetos?', { multilinha: true });
//
// Não depende de jQuery nem do Bootstrap (carrega em qualquer página). Fecha
// com Esc (= cancelar) e confirma com Enter; o foco volta para onde estava.
(function () {
  if (window.ifsAlerta) return;

  const TIPOS = {
    info:    { icone: 'fa-info-circle',          cor: '#17a2b8', titulo: 'Aviso' },
    sucesso: { icone: 'fa-check-circle',         cor: '#1B7D3D', titulo: 'Pronto' },
    aviso:   { icone: 'fa-exclamation-triangle', cor: '#d39e00', titulo: 'Atenção' },
    erro:    { icone: 'fa-times-circle',         cor: '#c82333', titulo: 'Algo deu errado' },
    pergunta:{ icone: 'fa-question-circle',      cor: '#1B7D3D', titulo: 'Confirmar' },
  };

  const css = `
    .ifs-dlg-fundo { position: fixed; inset: 0; z-index: 2000; background: rgba(20, 28, 24, .45);
      display: flex; align-items: center; justify-content: center; padding: 16px;
      opacity: 0; transition: opacity .15s ease; }
    .ifs-dlg-fundo.ifs-dlg-visivel { opacity: 1; }
    .ifs-dlg { background: #fff; border-radius: 12px; width: 100%; max-width: 440px;
      box-shadow: 0 18px 50px rgba(0, 0, 0, .25); overflow: hidden;
      transform: translateY(8px) scale(.98); transition: transform .15s ease;
      font-family: 'Source Sans Pro', -apple-system, 'Segoe UI', Roboto, sans-serif; }
    .ifs-dlg-visivel .ifs-dlg { transform: none; }
    .ifs-dlg-corpo { display: flex; gap: 14px; padding: 22px 22px 14px; }
    .ifs-dlg-icone { font-size: 1.9rem; line-height: 1; flex-shrink: 0; margin-top: 2px; }
    .ifs-dlg-titulo { font-size: 1.15rem; font-weight: 700; color: #212529; margin: 0 0 6px; }
    .ifs-dlg-texto { color: #4a4f55; font-size: 1rem; line-height: 1.5; white-space: pre-line;
      overflow-wrap: anywhere; margin: 0; }
    .ifs-dlg-campo { width: 100%; margin-top: 12px; padding: 8px 10px; font: inherit; font-size: .95rem;
      border: 1px solid #ced4da; border-radius: 6px; resize: vertical; }
    .ifs-dlg-campo:focus { outline: none; border-color: #2A9B4A; box-shadow: 0 0 0 3px rgba(27, 125, 61, .2); }
    .ifs-dlg-erro-campo { color: #c82333; font-size: .85rem; margin-top: 4px; display: none; }
    .ifs-dlg-botoes { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 22px 18px; }
    .ifs-dlg-btn { font: inherit; font-size: .95rem; padding: 7px 16px; border-radius: 6px;
      border: 1px solid transparent; cursor: pointer; }
    .ifs-dlg-btn:focus-visible { outline: 3px solid rgba(27, 125, 61, .35); outline-offset: 1px; }
    .ifs-dlg-btn-sec { background: #fff; border-color: #ced4da; color: #343a40; }
    .ifs-dlg-btn-sec:hover { background: #f1f3f5; }
    .ifs-dlg-btn-pri { background: #1B7D3D; color: #fff; }
    .ifs-dlg-btn-pri:hover { background: #0D4620; }
    .ifs-dlg-btn-perigo { background: #c82333; color: #fff; }
    .ifs-dlg-btn-perigo:hover { background: #a71d2a; }
    @media (max-width: 480px) {
      .ifs-dlg-botoes { flex-direction: column-reverse; }
      .ifs-dlg-btn { width: 100%; }
    }`;

  function instalarCss() {
    if (document.getElementById('ifs-dlg-css')) return;
    const st = document.createElement('style');
    st.id = 'ifs-dlg-css';
    st.textContent = css;
    document.head.appendChild(st);
  }

  // Diálogos abertos ao mesmo tempo entram numa fila (um de cada vez)
  let fila = Promise.resolve();

  function abrir(opcoes) {
    const vez = fila.then(() => mostrar(opcoes));
    fila = vez.catch(() => {});
    return vez;
  }

  function mostrar({ mensagem, titulo, tipo, botoes, campo }) {
    instalarCss();
    return new Promise(resolve => {
      const t = TIPOS[tipo] || TIPOS.info;
      const focoAnterior = document.activeElement;

      const fundo = document.createElement('div');
      fundo.className = 'ifs-dlg-fundo';
      const caixa = document.createElement('div');
      caixa.className = 'ifs-dlg';
      caixa.setAttribute('role', campo || botoes.length > 1 ? 'dialog' : 'alertdialog');
      caixa.setAttribute('aria-modal', 'true');
      const idTitulo = 'ifs-dlg-t' + Date.now();
      caixa.setAttribute('aria-labelledby', idTitulo);

      const corpo = document.createElement('div');
      corpo.className = 'ifs-dlg-corpo';
      const icone = document.createElement('i');
      icone.className = 'fas ' + t.icone + ' ifs-dlg-icone';
      icone.style.color = t.cor;
      icone.setAttribute('aria-hidden', 'true');
      const textos = document.createElement('div');
      textos.style.flex = '1';
      textos.style.minWidth = '0';
      const h = document.createElement('h2');
      h.className = 'ifs-dlg-titulo';
      h.id = idTitulo;
      h.textContent = titulo || t.titulo;
      const p = document.createElement('p');
      p.className = 'ifs-dlg-texto';
      p.textContent = mensagem == null ? '' : String(mensagem);   // texto puro: nada de HTML
      textos.append(h, p);

      let entrada = null, erroCampo = null;
      if (campo) {
        entrada = document.createElement(campo.multilinha ? 'textarea' : 'input');
        entrada.className = 'ifs-dlg-campo';
        if (campo.multilinha) entrada.rows = 3; else entrada.type = 'text';
        entrada.placeholder = campo.placeholder || '';
        entrada.value = campo.valor || '';
        if (campo.maxLength) entrada.maxLength = campo.maxLength;
        entrada.setAttribute('aria-label', mensagem);
        erroCampo = document.createElement('div');
        erroCampo.className = 'ifs-dlg-erro-campo';
        erroCampo.textContent = 'Preencha este campo.';
        textos.append(entrada, erroCampo);
      }
      corpo.append(icone, textos);

      const barra = document.createElement('div');
      barra.className = 'ifs-dlg-botoes';
      const els = botoes.map(b => {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'ifs-dlg-btn ' + (b.classe || 'ifs-dlg-btn-sec');
        el.textContent = b.texto;
        el.addEventListener('click', () => escolher(b.valor));
        barra.appendChild(el);
        return el;
      });

      caixa.append(corpo, barra);
      fundo.appendChild(caixa);
      document.body.appendChild(fundo);
      const overflowAnterior = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(() => fundo.classList.add('ifs-dlg-visivel'));

      const valorCancelar = botoes.find(b => b.cancelar)?.valor;
      const principal = els[els.length - 1];
      (entrada || principal).focus();

      function escolher(valor) {
        if (campo && valor === true) {
          const texto = entrada.value.trim();
          if (campo.obrigatorio && !texto) {
            erroCampo.style.display = 'block';
            entrada.focus();
            return;
          }
          valor = texto;
        } else if (campo) {
          valor = null;
        }
        document.removeEventListener('keydown', teclas, true);
        fundo.classList.remove('ifs-dlg-visivel');
        document.body.style.overflow = overflowAnterior;
        setTimeout(() => fundo.remove(), 150);
        if (focoAnterior && typeof focoAnterior.focus === 'function') focoAnterior.focus();
        resolve(valor);
      }

      function teclas(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          e.stopPropagation();
          escolher(valorCancelar);
        } else if (e.key === 'Enter' && !(entrada && campo.multilinha && !e.ctrlKey) && document.activeElement?.tagName !== 'BUTTON') {
          e.preventDefault();
          escolher(botoes[botoes.length - 1].valor);
        } else if (e.key === 'Tab') {
          // Mantém o foco dentro do diálogo
          const foco = [entrada, ...els].filter(Boolean);
          const i = foco.indexOf(document.activeElement);
          const prox = e.shiftKey ? (i <= 0 ? foco.length - 1 : i - 1) : (i === foco.length - 1 ? 0 : i + 1);
          e.preventDefault();
          foco[prox].focus();
        }
      }
      document.addEventListener('keydown', teclas, true);

      // Clique fora: só fecha avisos (numa confirmação seria fácil cancelar sem querer)
      fundo.addEventListener('mousedown', e => {
        if (e.target === fundo && botoes.length === 1) escolher(botoes[0].valor);
      });
    });
  }

  function tipoPelaMensagem(msg) {
    return /^\s*(erro|falha|não foi possível)/i.test(String(msg)) ? 'erro' : 'info';
  }

  window.ifsAlerta = function (mensagem, opcoes = {}) {
    return abrir({
      mensagem,
      titulo: opcoes.titulo,
      tipo: opcoes.tipo || tipoPelaMensagem(mensagem),
      botoes: [{ texto: opcoes.ok || 'OK', valor: undefined, classe: 'ifs-dlg-btn-pri', cancelar: true }],
    });
  };

  window.ifsConfirmar = function (mensagem, opcoes = {}) {
    return abrir({
      mensagem,
      titulo: opcoes.titulo,
      tipo: opcoes.tipo || (opcoes.perigo ? 'aviso' : 'pergunta'),
      botoes: [
        { texto: opcoes.cancelar || 'Cancelar', valor: false, cancelar: true },
        { texto: opcoes.confirmar || 'Confirmar', valor: true, classe: opcoes.perigo ? 'ifs-dlg-btn-perigo' : 'ifs-dlg-btn-pri' },
      ],
    });
  };

  // Devolve o texto digitado, ou null se cancelar
  window.ifsPerguntar = function (mensagem, opcoes = {}) {
    return abrir({
      mensagem,
      titulo: opcoes.titulo,
      tipo: opcoes.tipo || 'pergunta',
      campo: { multilinha: opcoes.multilinha, placeholder: opcoes.placeholder, valor: opcoes.valor, obrigatorio: opcoes.obrigatorio, maxLength: opcoes.maxLength },
      botoes: [
        { texto: opcoes.cancelar || 'Cancelar', valor: false, cancelar: true },
        { texto: opcoes.confirmar || 'Enviar', valor: true, classe: 'ifs-dlg-btn-pri' },
      ],
    });
  };
})();

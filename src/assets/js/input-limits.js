(function(){
  // Avisa o usuário quando um campo chega ao limite definido no HTML:
  // - texto (maxlength): o navegador já impede digitar além do limite (e corta
  //   o que for colado), então a mensagem aparece quando o limite é atingido;
  // - número (min/max): o navegador não impede a digitação, então a mensagem
  //   aparece e o campo fica inválido (setCustomValidity) até ser corrigido.
  // Os limites seguem as colunas do banco (ver src/db/ifsentral_bd.sql) e são
  // validados de novo no servidor.

  function msgEl(campo, criar){
    // Em .input-group (ícones do AdminLTE) a mensagem vai abaixo do grupo
    const alvo = campo.closest('.input-group') || campo;
    let el = alvo.nextElementSibling;
    if (el && el.classList.contains('input-limit-msg')) return el;
    if (!criar) return null;
    el = document.createElement('small');
    el.className = 'input-limit-msg text-danger';
    if (alvo !== campo) {
      // O grupo já tem margem inferior (mb-3): sobe a mensagem para junto dele
      el.style.marginTop = '-0.75rem';
      el.style.marginBottom = '0.75rem';
    }
    alvo.insertAdjacentElement('afterend', el);
    return el;
  }

  function mensagemNumero(campo){
    if (campo.value === '') return '';
    const valor = Number(campo.value);
    const max = campo.getAttribute('max');
    const min = campo.getAttribute('min');
    if (max !== null && valor > Number(max)) return `O valor máximo é ${Number(max).toLocaleString('pt-BR')}.`;
    if (min !== null && valor < Number(min)) return `O valor mínimo é ${Number(min).toLocaleString('pt-BR')}.`;
    return '';
  }

  function verificar(campo){
    let texto = '';
    if (campo.type === 'number') {
      texto = mensagemNumero(campo);
      campo.setCustomValidity(texto);
    } else if (campo.maxLength > 0 && campo.value.length >= campo.maxLength) {
      texto = `Máximo de ${campo.maxLength} caracteres.`;
    }

    const el = msgEl(campo, !!texto);
    if (!el) return;
    el.textContent = texto;
    el.style.display = texto ? 'block' : 'none';
  }

  // Delegação no document: cobre também campos criados depois pelo JS
  document.addEventListener('input', function(e){
    if (e.target.matches('input, textarea')) verificar(e.target);
  });
})();

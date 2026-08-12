(function(){
  // Global lightweight modal for session expired
  window.showSessionExpired = window.showSessionExpired || function(message){
    let modal = document.getElementById('session-expired-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'session-expired-modal';
      modal.innerHTML = `
        <div style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:2000;">
          <div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);"></div>
          <div style="background:#fff;border-radius:6px;padding:20px;max-width:420px;width:90%;z-index:2001;box-shadow:0 8px 24px rgba(0,0,0,0.2);font-family:Arial,Helvetica,sans-serif;">
            <h4 style="margin-top:0;margin-bottom:8px;">Sessão expirada</h4>
            <div id="session-expired-message" style="margin-bottom:16px;color:#333;"></div>
            <div style="text-align:right">
              <a id="session-expired-login" href="login.html" style="margin-right:8px;padding:8px 12px;background:#007bff;color:#fff;border-radius:4px;text-decoration:none;">Fazer login</a>
              <button id="session-expired-close" style="padding:8px 12px;background:#6c757d;color:#fff;border:none;border-radius:4px;">Fechar</button>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
      document.getElementById('session-expired-close').addEventListener('click', function(){ modal.remove(); });
    }
    const msgEl = document.getElementById('session-expired-message');
    if (msgEl) msgEl.textContent = message || 'Sua sessão expirou. Faça login novamente.';
  };

  // Wrap fetch to always set X-Requested-With and handle 401
  const _fetch = window.fetch.bind(window);
  window.fetch = function(resource, init){
    init = init || {};
    init.headers = init.headers || {};
    try {
      const hasXReq = Object.keys(init.headers).some(h => h.toLowerCase() === 'x-requested-with');
      if (!hasXReq) init.headers['X-Requested-With'] = 'XMLHttpRequest';
    } catch(e) {
      // ignore
    }
    return _fetch(resource, init).then(response => {
      if (response.status === 401) {
        response.clone().json().then(j => {
          const msg = j && j.error ? j.error : 'Não autenticado. Faça login primeiro.';
          window.showSessionExpired(msg);
        }).catch(() => {
          window.showSessionExpired('Não autenticado. Faça login primeiro.');
        });
      }
      return response;
    });
  };

  // Helper to safely parse JSON responses
  window.safeJson = async function(response){
    if (!response) throw new Error('Sem response');
    
    // Ler o body uma única vez como texto
    const bodyText = await response.text();
    
    if (!response.ok) {
      try {
        const j = JSON.parse(bodyText);
        throw new Error(j.error || JSON.stringify(j));
      } catch(e) {
        if (e instanceof SyntaxError) {
          throw new Error(bodyText || ('HTTP error ' + response.status));
        }
        throw e;
      }
    }
    
    const ct = response.headers.get('content-type') || '';
    if (ct.indexOf('application/json') === -1) {
      throw new Error('Resposta inválida (não-JSON): ' + bodyText.substring(0,200));
    }
    
    try {
      return JSON.parse(bodyText);
    } catch(e) {
      throw new Error('JSON inválido: ' + bodyText.substring(0,200));
    }
  };
})();

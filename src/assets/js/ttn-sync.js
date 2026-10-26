(function(){
  // O Lite não tem processo em segundo plano: cada acesso às páginas pede ao
  // servidor que busque no TTN os uplinks novos (Storage Integration). O
  // servidor limita a uma busca por minuto por aplicação, então chamar a cada
  // página é barato. sendBeacon não atrasa a página nem é cancelado se o
  // usuário navegar para outra.
  var url = 'api/ttn-sincronizar';
  try {
    if (navigator.sendBeacon && navigator.sendBeacon(url)) return;
  } catch (e) { /* cai para o fetch */ }
  try {
    window.fetch(url, { method: 'POST', keepalive: true, credentials: 'same-origin' }).catch(function(){});
  } catch (e) { /* sem suporte: a busca acontece no próximo acesso */ }
})();

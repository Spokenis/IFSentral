// Acessibilidade (axe, WCAG 2 A/AA), erros de console e layout no celular
const { chromium } = require('playwright-core');
const fs = require('fs');
const axe = fs.readFileSync(__dirname + '/node_modules/axe-core/axe.min.js', 'utf8');
const B = 'https://127.0.0.1:38443';
const OUT = process.argv[2];
const PAGINAS = {
  publico: ['/', '/login', '/registrar', '/esqueci-senha', '/explorar-projetos'],
  usuario: ['/meus-projetos', '/projeto?id=30', '/dispositivo?id=218', '/ver-projeto?id=30', '/meus-dispositivos', '/perfil', '/configuracoes', '/documentacao', '/novo-grafico?project_id=30', '/novo-dispositivo?project_id=30'],
  admin: ['/admin', '/admin/usuarios', '/admin/rate-limiting'],
};
const CONTAS = { usuario: ['vitima@teste.dev', 'Seg@2026xx'], admin: ['carga@teste.dev', 'Carga@2026'] };
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.HOME + '/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome' });
  const res = [];
  for (const [grupo, urls] of Object.entries(PAGINAS)) {
    for (const [nomeVp, vp] of [['desktop', { width: 1366, height: 800 }], ['celular', { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }]]) {
      const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: vp.width, height: vp.height }, isMobile: !!vp.isMobile, hasTouch: !!vp.hasTouch, deviceScaleFactor: vp.deviceScaleFactor || 1 });
      if (CONTAS[grupo]) {
        const r = await ctx.request.post(B + '/api/login', { data: { email: CONTAS[grupo][0], password: CONTAS[grupo][1] } });
        if (!r.ok()) { console.log('login falhou', grupo, r.status()); }
      }
      for (const u of urls) {
        const page = await ctx.newPage();
        const erros = [];
        page.on('console', m => { if (m.type() === 'error') erros.push(m.text().slice(0, 160)); });
        page.on('pageerror', e => erros.push('JS: ' + e.message.slice(0, 160)));
        const t0 = Date.now();
        const resp = await page.goto(B + u, { waitUntil: 'load', timeout: 60000 }).catch(e => null);
        const tempo = Date.now() - t0;
        await page.waitForTimeout(800);
        const larg = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth, vp: window.innerWidth }));
        let viol = [];
        if (nomeVp === 'desktop') {
          await page.addScriptTag({ content: axe });
          viol = await page.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map(v => ({ id: v.id, impacto: v.impact, n: v.nodes.length, ex: v.nodes[0]?.target?.join(' ') })));
        }
        const nome = (grupo + u).replace(/[^a-z0-9]+/gi, '_');
        await page.screenshot({ path: `${OUT}/telas/${nome}_${nomeVp}.png`, fullPage: nomeVp === 'celular' });
        res.push({ grupo, url: u, vp: nomeVp, status: resp ? resp.status() : 'erro', url_final: page.url().replace(B, ''), tempo_ms: tempo, transborda: larg.doc > larg.vp + 1 ? `${larg.doc}>${larg.vp}` : '', erros_console: erros, axe: viol });
        console.log(grupo.padEnd(8), nomeVp.padEnd(8), u.padEnd(30), resp ? resp.status() : 'erro', `${tempo}ms`, larg.doc > larg.vp + 1 ? 'TRANSBORDA ' + larg.doc : '', erros.length ? 'console:' + erros.length : '', viol.length ? 'axe:' + viol.map(v => v.id + '(' + v.n + ')').join(',') : '');
        await page.close();
      }
      await ctx.close();
    }
  }
  fs.writeFileSync(OUT + '/qualidade.json', JSON.stringify(res, null, 1));
  await browser.close();
})();

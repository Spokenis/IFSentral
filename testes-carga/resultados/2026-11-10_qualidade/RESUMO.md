# Qualidade e usuário — 10/11/2026

## Testes automatizados
- **PHPUnit**: 97 testes nas duas edições, em PHP 8.2 (produção) e 8.5, todos aprovados. Novos:
  `HtmlSanitizerTest` (16 tipos de ataque XSS na documentação editável, mais o que deve continuar
  funcionando), `AgregadosTest` (valores que vão para os gráficos), `DocumentacaoMarcadoresTest`
  e os da proteção do login em `RateLimiterTest`.
- **PHPStan nível 2**: nenhum defeito real (sobra só o ruído de variáveis vindas de `require`).
- **E2E**: completa 349/349, Lite 332/332.

## Acessibilidade (axe-core, WCAG 2 A/AA, 18 páginas)
- Antes: ~280 elementos com problema, quase todos de **contraste** (cores padrão do
  Bootstrap/AdminLTE), além de ícones e campos sem nome para leitor de tela e abas com ARIA
  incompleto.
- Corrigido com `src/assets/css/acessibilidade.css` (o "primário" passa a ser o verde da
  plataforma, #1B7D3D; links #0062cc; cinzas mais escuros; foco visível no teclado), nomes
  acessíveis (sino de notificações, seletores do admin, botão de busca, log do worker) e
  `role="presentation"` nas abas.
- Depois: só restam o aviso de abas da documentação já gravada no banco (o padrão novo
  está corrigido; "Restaurar padrão" na seção aplica) e 2 itens isolados.

## Lighthouse (página inicial e login)
| Página | Desempenho | Acessibilidade | Boas práticas | SEO |
|---|---|---|---|---|
| Início (desktop / celular) | 100 / 93 | 88→corrigido / 93 | 96 | 90→meta description adicionada |
| Login (desktop / celular) | 100 / 93 | 100 / 100 | 96 | 90 |
LCP 0,5 s no desktop e 2,6 s no celular simulado (3G lento); TBT 0 ms; CLS ≤ 0,05.

## Celular e Firefox
- 390×844 (celular): nenhuma das 18 páginas transborda na horizontal (`telas/*_celular.png`).
- Firefox 142: login, projetos, gráficos desenhados, abas, documentação e novo gráfico sem
  erros de JavaScript.

## Defeito de uso encontrado e corrigido
- O visitante sem conta via o aviso **"Sessão expirada — Faça login primeiro"** ao abrir a
  página inicial e "Explorar projetos" (a página perguntava se havia alguém logado e o
  tratamento global de 401 mostrava o aviso). Agora essas consultas usam `silenciar401`.

## Teste com usuários (pendente: precisa de participantes)
Material pronto em `testes-usabilidade/`: roteiro com 8 tarefas, questionário SUS em
português e `calcular_sus.py`.

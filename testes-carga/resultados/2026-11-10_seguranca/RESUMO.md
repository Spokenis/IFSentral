# Testes de segurança — 09–10/11/2026 (pilha isolada `ifs_carga` e ambiente E2E)

## Sem problema encontrado
| Teste | Resultado |
|---|---|
| Controle de acesso (IDOR): usuário comum contra projeto, dispositivos e gráfico de outro, 40 endpoints × GET/POST/PUT/DELETE (`idor.py`) | nada lido nem alterado; dados da vítima intactos |
| Endpoints sem login | só as rotas públicas respondem; `/api/ttn-sincronizar` sem corpo é público de propósito (Lite) |
| CSRF (sem token, formulário de outra origem) | 403 em todas as alterações |
| Sessão | ID novo no login; cookie antigo inválido após logout |
| Injeção SQL / JSON quebrado / corpo de 2 MB | recusados, sem erro de banco |
| 100 chaves de API erradas | 401 em todas |
| testssl (após ajuste) | só TLS 1.2/1.3, só ECDHE + AEAD; Heartbleed, ROBOT, POODLE, SWEET32, LUCKY13 etc.: não vulnerável |
| OWASP ZAP full scan (ativo) | 0 falhas, 134 verificações aprovadas, 9 avisos (abaixo) |

## Encontrado e corrigido
1. **Arquivos internos públicos (crítico):** `.env` (senhas), `docker-compose.yml`, dump do banco,
   log do worker e scripts de manutenção podiam ser baixados; `system-check.php` e
   `setup-security-tables.php` executavam pelo navegador. Bloqueio no `.htaccess` (lista do que é
   público) e no nginx (segunda barreira). Todos os caminhos dão 404.
2. **`.env` dentro da imagem Docker:** fora pelo `.dockerignore`; montado somente leitura.
3. **MySQL (3307) e WebSocket do MQTT (9001) abertos na rede** (o Docker passa por cima do UFW):
   agora só em 127.0.0.1.
4. **TLS** do site e do MQTT (8883) aceitava cifras CBC e troca RSA estática: perfil Mozilla
   "intermediate". Certificado autoassinado passa a ter SAN.
5. **Versões expostas** (nginx, Apache, PHP): ocultas.
6. **Cookie de sessão sem Secure/HttpOnly/SameSite** nas páginas que abrem a sessão antes do
   config.php (ZAP): definido no php.ini da imagem. O Lite já estava correto.
7. **Bloqueio de login fixo no código** (5 por conta, 20 por IP): agora configurável em
   Admin > Rate Limiting > Proteção do login — tentativas por conta, por IP (0 = sem limite),
   tempo, redes isentas (CIDR) e lista de bloqueados com botão de desbloquear (`login_admin.py`).
8. **Envio de foto de perfil falhava** (pasta `uploads/profile` do host sem permissão para o
   Apache): o contêiner web corrige ao iniciar.

## Avisos que ficam (baixo risco, documentados)
- CSP com `'unsafe-inline'`/`'unsafe-eval'`: as páginas têm scripts embutidos; tirar exige
  mover todo o JavaScript para arquivos.
- Cabeçalhos Cross-Origin-*-Policy ausentes; "Proxy Disclosure" (há um proxy, sem versão).
- Certificado autoassinado (usar um certificado válido no servidor público).

## Pendente de decisão
- O dump de instalação (`src/db/ifsentral_bd.sql`) traz 3 contas reais (2 Admin), dispositivos
  com chave de API e credenciais MQTT: uma instalação nova nasce com esses administradores.
- Trocar as senhas do `.env` do servidor e do PC (ficaram acessíveis pela rede).

Teste do broker MQTT (ACL entre dispositivos etc.) não foi refeito nesta rodada; a ACL por
tópico já tinha sido verificada em 08/11 (dispositivo não publica no tópico de outro).

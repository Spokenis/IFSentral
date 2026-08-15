# TODO: Correções de Português e E-mail

Todos os itens abaixo foram verificados e concluídos.

## Etapas

### ✅ 1. Textos visíveis ao usuário — "Email" → "E-mail"
Já corrigido em todos os arquivos listados (`configuracoes.php`, `perfil.php`, `login.html`, `register.html`, `admin-dashboard.php`, `admin-usuarios.php`, `gerenciar-projeto.php`).

### ✅ 2. Mensagens de erro de API — "Email" → "E-mail"
Já corrigido em todos os arquivos listados (`enviar_convite.php`, `atualizar_perfil.php`, `login_api.php`, `cadastrar_usuario.php`, `admin_usuarios.php`).

### ✅ 3. Comentários — "basado no email" → "baseado no e-mail" (14 arquivos)
Corrigido em `gerenciar-projeto.php`, `novo-dispositivo.php`, `deletar_grafico.php`, `listar_projetos.php`, `obter_chaves_dispositivo.php`, `gerenciar-dispositivo.php`, `obter_projeto.php`, `criar_grafico_avancado.php`, `obter_info_dispositivo.php`, `atualizar_grafico_avancado.php`, `obter_contagem_participantes.php`, `obter_stats_payloads.php`, `salvar_grafico_avancado.php`, `obter_dados_grafico_avancado.php`.

### ✅ 4. Outros erros de português
Já corrigido em `generate_mosquitto_acl.php` e `gerenciar-projeto.php`. Nota: `status = 'pending'` em `enviar_convite.php` é o valor literal gravado na coluna do banco (não um texto exibido) — não deve ser traduzido, ou os convites pendentes deixam de ser encontrados pela consulta.


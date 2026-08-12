# TODO: Implementar Controle de Envio de E-mails via Variável de Ambiente

## Etapas

### ✅ 1. Configurar Variável de Controle no `.env`
- [x] Adicionar `ENABLE_EMAIL_FEATURES=true` em `src/config/.env`
- [x] Registrar constante `ENABLE_EMAIL_FEATURES` em `src/config/config.php`

### ✅ 2. Novo Método `enviarEmailConvite` no Serviço de E-mail
- [x] Adicionar método `public function enviarEmailConvite()` em `src/core/ServicoEmail.php`

### ✅ 3. Refatorar Endpoint de Convites (`enviar_convite.php`)
- [x] Buscar dados do projeto e remetente
- [x] Disparo condicional do e-mail baseado em `ENABLE_EMAIL_FEATURES`
- [x] Incluir `email_dispatched` na resposta JSON

### ✅ 4. Ajustar Cadastro de Usuário (`cadastrar_usuario.php`)
- [x] Inserir `is_verified = 1` quando `ENABLE_EMAIL_FEATURES` for false
- [x] Isolar geração de token e envio de e-mail em condicional

### ✅ 5. Todas as etapas concluídas
- [x] Implementação finalizada

## Resumo das Alterações

| Arquivo | Alteração |
|---------|-----------|
| `src/config/.env` | Adicionado `ENABLE_EMAIL_FEATURES=true` |
| `src/config/config.php` | Adicionado `define('ENABLE_EMAIL_FEATURES', ...)` |
| `src/core/ServicoEmail.php` | Adicionado método `enviarEmailConvite()` |
| `src/api/enviar_convite.php` | Disparo condicional de e-mail + `email_dispatched` |
| `src/auth/cadastrar_usuario.php` | `is_verified=1` quando e-mail desligado; token só gerado se habilitado |

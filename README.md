# IFSentral Lite - Plataforma IoT Smart Campus

Plataforma integrada de Internet das Coisas (IoT) para gerenciamento de projetos, dispositivos de hardware (sensores, ESP32, LoRaWAN via TTN) e visualização de dados em tempo real.

O IFSentral Lite é a versão **simplificada** do IFSentral, feita para rodar em hospedagem compartilhada ou em um servidor Apache tradicional (ex.: Hostinger, servidor do campus) — sem Docker e sem broker MQTT. Os dispositivos enviam dados via **HTTP (POST)** ou pelo **webhook do The Things Network (TTN)**.

## Requisitos do servidor

* Apache com PHP 8.x e as extensões `pdo_mysql`, `mbstring`, `json`, `openssl`
* MySQL/MariaDB (pode ser o banco já oferecido pelo provedor)
* Acesso ao phpMyAdmin (ou similar) para importar o schema

Nada mais é obrigatório. O site se adapta sozinho ao servidor:

| Situação do servidor | Como o site se comporta |
| --- | --- |
| Domínio/VirtualHost próprio | Abre em `http://dominio/` |
| Subpasta (ex.: `/var/www/html/site1/`) | Abre em `http://servidor/site1/` — nenhum caminho para ajustar |
| `.htaccess` respeitado e `mod_rewrite` ativo | URLs limpas: `/login`, `/meus-projetos`, `/api/...` |
| `.htaccess` ignorado (`AllowOverride None`) | Mesmas páginas em `/index.php/login`, `/index.php/api/...` |
| Com HTTPS | Cookie de sessão seguro e HSTS ligados automaticamente |
| Só HTTP | Funciona normalmente (o redirect para HTTPS é opcional, ver `FORCE_HTTPS`) |

## Instalação

### 1. Enviar os arquivos

Copie o conteúdo desta pasta (`IFSentral/`) para o diretório do site no servidor — por exemplo `/var/www/html/site1/` ou `public_html/`. O `index.php` fica direto nesse diretório; é ele que atende todas as páginas.

**Não envie** o que não é necessário para o site rodar: `.git/`, `tests/`, `phpunit.xml`, `*.md`. A pasta `vendor/` precisa ir junto (ou rode `composer install --no-dev` no servidor).

### 2. Criar o banco de dados

No phpMyAdmin do servidor, crie um banco de dados (ex.: `ifsentral_bd`) e um usuário com permissão total sobre ele. Em seguida importe o dump pronto:

```
src/db/ifsentral_bd.sql
```

Esse arquivo já cria todas as tabelas necessárias (usuários, projetos, dispositivos, payloads, rate limiting, 2FA etc.). Depois de importar, apague o `.sql` do servidor.

### 3. Configurar as credenciais

Copie o arquivo de exemplo e edite com os dados reais do seu banco:

```bash
cp src/config/env.example.php src/config/env.php
```

Preencha pelo menos:

* `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — credenciais do banco criado na etapa 2
* `ENABLE_EMAIL_FEATURES` — `false` libera o cadastro de contas sem confirmação por e-mail; para usar `true`, preencha também os campos `SMTP_*`
* `APP_URL` — opcional; endereço público do site (com a subpasta, se houver), usado nos links enviados por e-mail
* `FORCE_HTTPS` — mude para `true` somente se o servidor tiver HTTPS

O formato antigo `src/config/.env` continua funcionando, mas prefira o `env.php`: se o servidor ignorar o `.htaccess`, um `.env` dentro da pasta do site pode ser baixado por qualquer pessoa, e um arquivo PHP não. Nenhum dos dois deve ser commitado (já estão no `.gitignore`).

### 4. Ajustar permissões

O Apache/PHP precisa conseguir escrever em `uploads/` (fotos de perfil) e em `logs/` (criada automaticamente no primeiro aviso de rate limiting):

```bash
mkdir -p logs && chmod -R 755 logs uploads
```

### 5. Tabelas de segurança e validação

Rode uma vez, por SSH (estes dois scripts só funcionam pela linha de comando):

```bash
php setup-security-tables.php
php system-check.php
```

`system-check.php` confirma se a configuração, o banco e as pastas estão corretos.

### 6. Acessar a aplicação

Abra o endereço do site no navegador (`http://servidor/site1/`, `https://seudominio.com/` etc.). Se o servidor não aplicar o `.htaccess`, você será levado automaticamente para `.../index.php/` — é o comportamento esperado, e tudo funciona da mesma forma.

### Rotas

As rotas públicas ficam em `src/config/routes.php` (URL => arquivo). Os links dentro das páginas são sempre **relativos** (`href="login"`, `fetch('api/...')`, sem `/` no início) — é isso que permite instalar em subpasta. Em redirects no PHP, use `app_url('rota')`.

## Como os dispositivos enviam dados

Sem broker MQTT, os dispositivos (ESP32, sensores, etc.) enviam telemetria por dois caminhos HTTP:

* **`POST api/enviar-payload`** — endpoint padrão, autenticado por `X-Api-Key` (chave gerada ao cadastrar o dispositivo)
* **`POST api/ttn-webhook?device_id=ID`** — webhook para integrações via The Things Network (LoRaWAN)

Os caminhos são relativos ao endereço do site (com `index.php/` na frente quando não há URLs limpas). A página **Documentação** dentro da aplicação mostra a URL completa já pronta para copiar, além de detalhes de payload, rate limiting e exemplos de código.

## Manutenção

### Backup do banco de dados

Pelo phpMyAdmin: **Exportar** → formato SQL. Ou, se tiver acesso SSH:

```bash
mysqldump -u SEU_USUARIO -p ifsentral_bd > backup_$(date +%F).sql
```

### Logs

A pasta `logs/` guarda avisos de rate limiting (`rate_limit_warnings.log`). Não há mais logs de worker MQTT nesta versão.

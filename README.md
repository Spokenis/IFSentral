# IFSentral Lite - Plataforma IoT Smart Campus

Plataforma integrada de Internet das Coisas (IoT) para gerenciamento de projetos, dispositivos de hardware (sensores, ESP32, LoRaWAN via TTN) e visualização de dados em tempo real.

O IFSentral Lite é a versão **simplificada** do IFSentral, feita para rodar em hospedagem compartilhada ou em um servidor Apache tradicional (ex.: Hostinger, servidor do campus) — sem Docker e sem broker MQTT. Os dispositivos enviam dados via **HTTP (POST)** ou pelo **webhook do The Things Network (TTN)**.

## Requisitos do servidor

* Apache com `mod_rewrite` e `mod_headers` habilitados
* PHP 8.x com as extensões `pdo_mysql`, `mbstring`, `json`, `openssl`
* MySQL/MariaDB (pode ser o banco já oferecido pelo provedor)
* Acesso ao phpMyAdmin (ou similar) para importar o schema

## Instalação

### 1. Enviar os arquivos

Copie todo o conteúdo desta pasta (`IFSentral/`) para a raiz do domínio no servidor — normalmente `public_html/` (Hostinger) ou `/var/www/html/` (Apache próprio). Por FTP/SFTP ou pelo gerenciador de arquivos do painel.

### 2. Criar o banco de dados

No phpMyAdmin do servidor, crie um banco de dados (ex.: `ifsentral_bd`) e um usuário com permissão total sobre ele. Em seguida importe o dump pronto:

```
src/db/ifsentral_bd.sql
```

Esse arquivo já cria todas as tabelas necessárias (usuários, projetos, dispositivos, payloads, rate limiting, 2FA etc.).

### 3. Configurar as credenciais

Copie o arquivo de exemplo e edite com os dados reais do seu banco/e-mail:

```bash
cp src/config/.env.example src/config/.env
```

Edite `src/config/.env` e preencha pelo menos:

* `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — credenciais do banco criado na etapa 2
* `APP_URL` e `ALLOWED_ORIGINS` — o domínio real onde o site vai rodar
* `SMTP_*` — opcional; sem isso, defina `ENABLE_EMAIL_FEATURES=false` para liberar o cadastro de contas sem confirmação por e-mail

O arquivo `.env` nunca deve ser commitado (já está no `.gitignore`).

### 4. Ajustar permissões

Garanta que o Apache/PHP consiga escrever nestas pastas:

```bash
chmod -R 755 logs uploads
```

### 5. Tabelas de segurança e validação

Rode uma vez (por SSH, se disponível, ou copie o conteúdo para um script acessível via navegador temporariamente):

```bash
php setup-security-tables.php
php system-check.php
```

`system-check.php` confirma se a configuração, o banco e as pastas estão corretos.

### 6. Acessar a aplicação

Abra `https://seudominio.com/` no navegador. O `.htaccess` já cuida das URLs limpas (`/login`, `/meus-projetos`, `/api/...` etc.) — nada precisa ser configurado manualmente no Apache além de `mod_rewrite` e `mod_headers` estarem ativos.

Se o seu provedor já força HTTPS automaticamente (comum em painéis com essa opção), você pode remover o bloco de redirect HTTPS no topo do `.htaccess` para evitar redirecionamentos duplicados.

## Como os dispositivos enviam dados

Sem broker MQTT, os dispositivos (ESP32, sensores, etc.) enviam telemetria por dois caminhos HTTP:

* **`POST /api/enviar-payload`** — endpoint padrão, autenticado por `X-Api-Key` (chave gerada ao cadastrar o dispositivo)
* **`POST /api/ttn-webhook?device_id=ID`** — webhook para integrações via The Things Network (LoRaWAN)

Detalhes de payload, rate limiting e exemplos de código estão em `/documentacao` dentro da própria aplicação.

## Manutenção

### Backup do banco de dados

Pelo phpMyAdmin: **Exportar** → formato SQL. Ou, se tiver acesso SSH:

```bash
mysqldump -u SEU_USUARIO -p ifsentral_bd > backup_$(date +%F).sql
```

### Logs

A pasta `logs/` guarda avisos de rate limiting (`rate_limit_warnings.log`). Não há mais logs de worker MQTT nesta versão.

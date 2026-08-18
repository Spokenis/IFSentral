# IFSentral - Plataforma IoT Smart Campus

Plataforma integrada de Internet das Coisas (IoT) para gerenciamento de projetos, dispositivos de hardware (sensores, ESP32, LoRaWAN via TTN) e visualização de dados em tempo real. Documentação técnica para implantação.

## Arquitetura do Sistema

O sistema opera sob containers (Docker e Docker Compose) divididos nos seguintes serviços:
1. Web Server: Apache + PHP 8.x
2. Banco de Dados: MySQL 8.0
3. Broker MQTT: Eclipse Mosquitto
4. Worker MQTT: Processo PHP em background (ifsentral_worker)

Atenção: Todo comando PHP deve ser executado obrigatoriamente através do container Web. A execução direta no host falhará por incapacidade de resolução da rede interna do Docker. Utilize o prefixo `docker compose exec web php`.

## Procedimento de Instalação

### 0. Pré-requisitos
* Docker Engine e Docker Compose v2 (plugin `docker compose`) instalados no host. Se o host ainda não tem Docker, o script `get-docker.sh` incluso no repositório instala a versão oficial (`sudo ./get-docker.sh`).
* `git` instalado para obter o código-fonte.

### 1. Obter o Código
Clone o repositório no diretório de instalação (ajuste o caminho conforme necessário).

```bash
sudo mkdir -p /opt/ifsentral
sudo chown $USER:$USER /opt/ifsentral
git clone https://github.com/Spokenis/IFSentral.git /opt/ifsentral
cd /opt/ifsentral
```

### 2. Preparação do Ambiente
Configure as credenciais da aplicação.

```bash
cp src/config/.env.example .env
nano .env
```
Parâmetros obrigatórios no `.env`:
* DB_PASS e DB_ROOT_PASS
* MQTT_PASSWORD (necessário para a autenticação do worker)
* APP_URL (use `http://localhost:8080` para testes locais; a aplicação redireciona automaticamente para HTTPS, então o acesso real ocorrerá pela porta HTTPS — veja a seção "Acessando a Aplicação")

Atenção: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `MQTT_HOST`, `MQTT_PORT`, `MQTT_USERNAME` e `MQTT_PASSWORD` são injetados nos containers `web`/`worker` diretamente pelo `docker-compose.yml`. As demais variáveis (`APP_URL`, `SMTP_*`, `SESSION_*` etc.) são lidas do arquivo `.env`, que é copiado para dentro da imagem no momento do build (etapa 5). **Se você editar o `.env` depois de já ter buildado a imagem, é necessário rodar `docker compose up -d --build` novamente para que a mudança tenha efeito.**

### 3. Configuração SSL
A geração de certificados é pré-requisito para o container Web iniciar o Apache.

```bash
chmod +x setup-ssl.sh
./setup-ssl.sh
```

### 4. Permissões de Sistema de Arquivos
Crie as pastas necessárias e atribua a propriedade ao usuário do Apache (www-data / UID 33).

```bash
mkdir -p logs uploads/profile
sudo chmod -R 755 logs uploads
sudo chown -R 33:33 logs uploads
```

### 5. Inicialização dos Containers
Construa e inicie os serviços em segundo plano.

```bash
docker compose up -d --build
```

O container `db` leva alguns segundos para concluir a importação do schema inicial (`src/db/ifsentral_bd.sql`) antes de ser considerado "healthy"; os containers `web` e `worker` só sobem depois disso. Acompanhe até todos os serviços aparecerem como `running`/`healthy` antes de seguir para a próxima etapa:

```bash
docker compose ps
# ou, para acompanhar em tempo real:
docker compose logs -f
```

### 6. Estrutura de Segurança do Banco de Dados
Instancie as tabelas de rate limit, logs de acesso e 2FA.

```bash
docker compose exec web php setup-security-tables.php
```

### 7. Configuração e Autenticação MQTT
Gere as credenciais MQTT dos dispositivos cadastrados no banco e reinicie o broker para carregá-las. Execute estritamente na ordem abaixo.

```bash
docker compose exec web php src/mqtt/generate_mqtt_credentials.php
docker compose exec web php src/mqtt/generate_mosquitto_passwd.php
docker compose exec web php src/mqtt/generate_mosquitto_acl.php

docker compose restart mosquitto
```

`generate_mosquitto_passwd.php` já garante automaticamente que o usuário `admin` (usado pelo worker MQTT) exista no arquivo de senhas com a `MQTT_PASSWORD` atual do `.env` — não é necessário recriá-lo manualmente. O `docker compose restart mosquitto` continua sendo necessário para que o broker carregue as credenciais dos **dispositivos** recém-gerados (o Mosquitto só lê `passwords.txt`/`acl.acl` na própria inicialização).

### 8. Validação do Sistema
Execute o script de diagnóstico para confirmar a integridade e a comunicação de todos os serviços.

```bash
docker compose exec web php system-check.php
```

### 9. Acessando a Aplicação
O `docker-compose.yml` expõe HTTP na porta `${HOST_HTTP_PORT:-8080}` e HTTPS na porta `${HOST_HTTPS_PORT:-8443}` do host. O `.htaccess` da aplicação redireciona **toda** requisição HTTP para HTTPS automaticamente — portanto acesse sempre pela porta HTTPS:

```
https://localhost:8443
```

Como a etapa 3 gera um certificado autoassinado (a menos que você tenha instalado um certificado válido em `/etc/ssl/`), o navegador exibirá um aviso de certificado não confiável na primeira visita — isso é esperado em ambiente local/desenvolvimento. Em produção, substitua os certificados em `/etc/ssl/certs/ifsentral-chain.crt` e `/etc/ssl/private/ifsentral.key` por certificados válidos (ex.: Let's Encrypt) antes de expor a aplicação publicamente.

### 10. Agendamento de Tarefas (Cron) — opcional
`src/mqtt/mqtt_health_check.php` foi escrito para o deploy legado sem Docker (gerencia o worker como processo em background via PID/`nohup` no próprio host). Sob Docker Compose isso não se aplica: o worker roda isolado no container `worker`, e é o próprio Docker (`restart: unless-stopped` em `docker-compose.yml`) quem já reinicia o container automaticamente se o processo cair — não é necessário (nem seguro) rodar este script via `docker compose exec web ...` para esse fim; fora do container `worker` ele agora só registra um log e não faz nada.

Para acompanhar a saúde do worker sob Docker, prefira:

```bash
# Ver se o container está saudável e há quanto tempo está rodando:
docker compose ps worker

# Acompanhar a conexão com o broker em tempo real:
docker compose logs -f worker
```

Se ainda assim quiser um alerta externo periódico (ex.: para notificar caso o container fique preso em restart loop), agende a checagem no host contra o próprio Docker, não contra o script PHP:

```bash
*/5 * * * * docker inspect ifsentral_worker --format '{{.State.Status}}' | grep -qv '^running$' && echo "IFSentral worker fora do ar" # substitua pelo canal de alerta desejado
```

## Procedimentos de Manutenção

### Cadastro de Novos Dispositivos
Após registrar um dispositivo via interface web, regere as chaves do broker.

```bash
docker compose exec web php src/mqtt/generate_mqtt_credentials.php
docker compose exec web php src/mqtt/generate_mosquitto_passwd.php
docker compose exec web php src/mqtt/generate_mosquitto_acl.php
docker compose restart mosquitto
```

### Logs de Telemetria
Monitore a injeção de dados via MQTT em tempo real.

```bash
docker compose logs -f worker
```

### Backup de Dados
Comando para extração de dump do banco de dados (substitua a senha de acordo com o arquivo `.env`).

```bash
docker exec -i ifsentral_db mysqldump -u root -p'SENHA_ROOT' ifsentral_bd > /opt/backups/ifsentral_$(date +%F).sql
```
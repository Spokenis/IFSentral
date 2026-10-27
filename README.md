# IFSCentral - Plataforma IoT Smart Campus

Plataforma integrada de Internet das Coisas (IoT) para gerenciamento de projetos, dispositivos de hardware (sensores, ESP32, LoRaWAN via TTN) e visualização de dados em tempo real. Documentação técnica para implantação.

## Arquitetura do Sistema

O sistema opera sob containers (Docker e Docker Compose) divididos nos seguintes serviços:
1. Proxy de entrada (`proxy`): nginx, recebe o HTTPS (portas 8080/8443) e reparte as requisições entre os dois contêineres web
2. Web Server (`web` e `web-2`): Apache + PHP 8.x, duas cópias iguais — permite atualizar sem tirar o site do ar
3. Banco de Dados (`db`): MySQL 8.0
4. Broker MQTT (`mosquitto`): Eclipse Mosquitto
5. Worker MQTT (`worker`): processo PHP que grava no banco o que chega pelo broker
6. Ponte TTN (`ttn-bridge`): recebe os uplinks do The Things Network
7. Backup (`backup`): faz o backup do banco e das fotos de perfil todo dia

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

Atenção: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `MQTT_HOST`, `MQTT_PORT`, `MQTT_USERNAME` e `MQTT_PASSWORD` são injetados nos containers `web`/`worker` diretamente pelo `docker-compose.yml`. As demais variáveis (`APP_URL`, `SMTP_*`, `SESSION_*` etc.) são lidas do arquivo `.env`, que é copiado para dentro da imagem no momento do build (etapa 5). **Se você editar o `.env` depois da instalação, aplique a mudança com `sudo manutencao/atualizar.sh --sem-git`** (reconstrói a imagem sem tirar o site do ar; veja "Atualizar sem tirar o site do ar").

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

O container `db` leva de um a três minutos para concluir a importação do schema inicial (`src/db/ifsentral_bd.sql`) antes de ser considerado "healthy"; os demais containers só sobem depois disso. Acompanhe até todos os serviços aparecerem como `running`/`healthy` antes de seguir para a próxima etapa:

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

`generate_mosquitto_passwd.php` já garante automaticamente que o usuário `admin` (usado pelo worker MQTT) exista no arquivo de senhas com a `MQTT_PASSWORD` atual do `.env` — não é necessário recriá-lo manualmente. O reinício do broker só é necessário aqui, na instalação. Depois dela, o sistema mantém as credenciais sozinho: cada mudança feita pela interface web é aplicada no broker sem reiniciá-lo (veja "Cadastro de Novos Dispositivos").

### 8. Integração com o The Things Network (opcional)

O contêiner `ttn-bridge` conecta, por TLS (porta 8883), ao MQTT do TTN de cada projeto que tiver a integração configurada (aba **Configurações** do projeto → cartão **The Things Network**) e grava os uplinks em tempo real. A cada 5 minutos ele também busca na Storage Integration (HTTPS, porta 443) o que chegou enquanto a conexão esteve fora. Só é preciso que o servidor acesse a internet — o TTN não precisa alcançar o servidor.

```bash
sudo docker compose logs -f ttn-bridge
```

**Banco criado antes de 26/10/2026:** a migração `src/db/migracoes/2026-10-26_integracao_ttn.sql` é aplicada pelo `manutencao/atualizar.sh` (ou à mão, uma vez, com `sudo docker compose run --rm --no-deps --entrypoint php web src/db/aplicar-migracoes.php`).

### 9. Validação do Sistema
Execute o script de diagnóstico para confirmar a integridade e a comunicação de todos os serviços.

```bash
docker compose exec web php system-check.php
```

### 10. Acessando a Aplicação
O `docker-compose.yml` expõe HTTP na porta `${HOST_HTTP_PORT:-8080}` e HTTPS na porta `${HOST_HTTPS_PORT:-8443}` do host. O `.htaccess` da aplicação redireciona **toda** requisição HTTP para HTTPS automaticamente — portanto acesse sempre pela porta HTTPS:

```
https://localhost:8443
```

Como a etapa 3 gera um certificado autoassinado (a menos que você tenha instalado um certificado válido em `/etc/ssl/`), o navegador exibirá um aviso de certificado não confiável na primeira visita — isso é esperado em ambiente local/desenvolvimento. Em produção, substitua os certificados em `/etc/ssl/certs/ifsentral-chain.crt` e `/etc/ssl/private/ifsentral.key` por certificados válidos (ex.: Let's Encrypt) antes de expor a aplicação publicamente.

### 11. Agendamento de Tarefas (Cron) — opcional
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
*/5 * * * * docker inspect ifsentral_worker --format '{{.State.Status}}' | grep -qv '^running$' && echo "IFSCentral worker fora do ar" # substitua pelo canal de alerta desejado
```

## Procedimentos de Manutenção

### Cadastro de Novos Dispositivos
Não há nada a fazer no servidor: ao cadastrar, excluir ou regenerar a senha MQTT de um dispositivo pela interface web, o sistema atualiza as credenciais do broker sozinho, sem reiniciá-lo. **Não reinicie o `mosquitto` para isso** — reiniciar o broker derruba as conexões, e as mensagens publicadas nos segundos em que o worker ainda não voltou se perdem.

### Logs de Telemetria
Monitore a injeção de dados via MQTT em tempo real.

```bash
docker compose logs -f worker
```

### Backup automático
O contêiner `backup` faz, todo dia às 03:00, um backup do banco de dados e das fotos de perfil, e apaga os backups com mais de 14 dias. O dump usa `--single-transaction`: o site continua funcionando normalmente durante o backup. Horários, prazo e pasta são configurados no `.env` (`BACKUP_HORARIOS`, `BACKUP_MANTER_DIAS`, `BACKUP_DIR`).

Os backups ficam em `backups/` (ou em `BACKUP_DIR`), legíveis só pelo root — eles contêm hashes de senha e chaves de API:

```text
ifscentral_2026-10-27_030000_auto.sql.gz          banco de dados
ifscentral_2026-10-27_030000_auto.uploads.tar.gz  fotos de perfil
backup.log                                        histórico de cada backup
```

```bash
# Fazer um backup agora
sudo docker compose exec backup bash /backup/fazer-backup.sh manual

# Ver se os backups estão em dia: "unhealthy" = o último backup tem mais de 26 horas
sudo docker compose ps backup
sudo tail backups/backup.log
```

**Guarde uma cópia fora do servidor.** Um backup no mesmo disco não protege contra a perda do disco ou do servidor. Aponte `BACKUP_DIR` para outro disco, ou copie a pasta periodicamente para outra máquina (ex.: `rsync -a /opt/ifsentral/backups/ outra-maquina:/backups/ifscentral/`). O `.env` e os certificados (`/etc/ssl/...ifsentral*`) não entram no backup: guarde uma cópia deles à parte, uma vez.

### Restaurar um backup

```bash
sudo manutencao/restaurar-backup.sh                    # lista os backups
sudo manutencao/restaurar-backup.sh ifscentral_2026-10-27_030000_auto.sql.gz
```

O script pede confirmação, faz antes um backup do estado atual (rótulo `antes-restauracao`, para poder desfazer), para o worker e a ponte TTN durante a restauração e os religa no fim. O site continua no ar.

### Atualizar sem tirar o site do ar

```bash
cd /opt/ifsentral
sudo manutencao/atualizar.sh
```

O script:
1. baixa a versão nova (`git pull`; use `--sem-git` para atualizar com o código que já está na pasta, por exemplo depois de editar o `.env`);
2. faz um backup (`antes-atualizacao`);
3. constrói a imagem nova enquanto a versão atual continua atendendo;
4. aplica as migrações pendentes de `src/db/migracoes/` (registradas na tabela `schema_migrations`);
5. troca `web` e `web-2` um de cada vez: tira o contêiner do proxy, recria, espera ele ficar saudável (`api/saude`: PHP, configuração e banco) e só então o devolve ao proxy;
6. troca o worker MQTT e a ponte TTN (o broker guarda as mensagens enquanto o worker reinicia, e a ponte recupera pelo Storage do TTN);
7. aplica mudanças na configuração do nginx (`docker/nginx/`) sem reiniciar o proxy, e confere o site.

Se um contêiner novo não ficar saudável, o script volta sozinho para a versão anterior — a versão com problema nunca chega a receber acessos. Num teste com acessos contínuos durante a atualização (páginas, API logada, envio de dados por HTTP e MQTT), não houve nenhuma requisição com erro nem mensagem perdida, inclusive quando a versão nova estava quebrada e foi revertida.

**Migrações** em `src/db/migracoes/` devem só acrescentar (tabelas, colunas opcionais, índices): durante a troca, a versão antiga e a nova usam o mesmo banco ao mesmo tempo.

**Primeira vez com esta versão:** o proxy e o segundo contêiner web ainda não existem no servidor. Faça a transição uma vez, num horário de pouco uso (o site fica alguns segundos fora):

```bash
cd /opt/ifsentral
git pull
sudo docker compose up -d --build
sudo docker compose ps        # aguarde todos "healthy"/"running"
```

Daí em diante, use sempre `manutencao/atualizar.sh`.

### Atualizar o banco ou o broker
O `atualizar.sh` não reinicia o `db` nem o `mosquitto`: sem réplicas, reiniciá-los interrompe o serviço por alguns segundos (o site perde o banco; os dispositivos MQTT reconectam, e mensagens publicadas nesse intervalo podem se perder). Faça isso raramente, num horário de pouco uso, depois de um backup:

```bash
sudo docker compose exec backup bash /backup/fazer-backup.sh antes-atualizacao
sudo docker compose pull db mosquitto
sudo docker compose up -d db mosquitto
```

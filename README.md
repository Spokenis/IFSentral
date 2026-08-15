# IFSentral — Plataforma IoT Smart Campus

O **IFSentral** é uma plataforma integrada de Internet das Coisas (IoT) desenvolvida para gerenciar projetos, dispositivos de hardware (sensores, ESP32, LoRaWAN via TTN) e visualização de dados em tempo real (gráficos avançados e telemetria) em ambiente de campus inteligente.

Este guia é destinado à **Equipe de Tecnologia da Informação (TI)** do campus para orientar a implantação da aplicação em um servidor local da instituição.

---

## 🏗️ Arquitetura do Sistema

O sistema foi containerizado utilizando **Docker** e **Docker Compose**, garantindo isolamento, facilidade de manutenção e reproducibilidade. A stack é composta por:
1. **Web Server (Apache + PHP 8.x):** Responsável por servir a interface web (AdminLTE) e as APIs REST de gerenciamento e ingestão HTTP.
2. **Banco de Dados (MySQL 8.0):** Armazena usuários, projetos, permissões, chaves de API e históricos de payloads.
3. **Broker MQTT (Eclipse Mosquitto):** Gerencia a mensageria MQTT para os dispositivos de hardware com autenticação e ACLs restritas.
4. **MQTT Subscriber (Worker PHP em Background — container `ifsentral_worker`):** Consome as mensagens MQTT do broker e injeta os payloads no banco de dados em tempo real.

---

## ⚙️ Pré-requisitos de Infraestrutura

Antes de iniciar a instalação no servidor local (recomenda-se **Ubuntu Server 22.04 LTS** ou superior), certifique-se de que o servidor possui:
* **Docker** (versão 24.0+)
* **Docker Compose** (versão 2.20+)
* **Portas livres na rede/firewall:**
  * `80` (HTTP) / `443` (HTTPS) — Acesso web à plataforma.
  * `1883` (MQTT) — Comunicação dos dispositivos IoT com o broker (texto puro).
  * `8883` (MQTT sobre TLS) — Recomendado para dispositivos fora da rede local/confiável; usa os mesmos certificados gerados pelo `setup-ssl.sh`.
  * `8080` ou porta customizada para testes locais (configurável via `.env`).

---

## 🚀 Passo a Passo para Implantação

### 1. Clonar ou Transferir o Repositório
Transfira os arquivos do sistema para o diretório de produção no servidor (ex: `/var/www/ifsentral` ou `/opt/ifsentral`).

```bash
cd /opt/ifsentral
```

### 2. Configurar as Variáveis de Ambiente (`.env`)
Copie o arquivo de exemplo e edite com as credenciais definitivas da instituição:

```bash
cp src/config/.env.example .env
nano .env
```

**Parâmetros críticos a configurar no `.env`:**
* `DB_PASS` e `DB_ROOT_PASS`: Defina senhas fortes para o banco de dados MySQL.
* `MQTT_PASSWORD`: Defina a senha do administrador MQTT.
* `APP_URL`: URL base da aplicação no campus (ex: `http://ifsentral.seu-campus.edu.br` ou IP do servidor).
* `ENABLE_EMAIL_FEATURES`: Defina como `true` caso possuam servidor SMTP configurado, ou `false` para cadastro de usuários com ativação automática imediata. **Atenção:** a recuperação de senha ("Esqueci minha senha") depende de e-mail para funcionar — com `ENABLE_EMAIL_FEATURES=false` (ou SMTP mal configurado), o sistema segue respondendo com sucesso genérico por segurança, mas o e-mail nunca chega e o usuário fica sem forma de redefinir a senha sozinho.

### 3. Configurar SSL e Diretórios do Host
O `docker-compose.yml` monta certificados e configurações a partir de caminhos fixos no host (`/etc/ifsentral`, `/etc/ssl/certs/ifsentral-chain.crt`, `/etc/ssl/private/ifsentral.key`). **Este passo é obrigatório antes do `docker-compose up`** — sem ele, o Docker cria esses caminhos como diretórios vazios e o container web falha ao iniciar o Apache/SSL.

```bash
chmod +x setup-ssl.sh
./setup-ssl.sh
```

O script cria os diretórios necessários e, caso a instituição ainda não possua certificados válidos (ex: emitidos por uma CA ou Let's Encrypt), gera um certificado autoassinado temporário em `/etc/ssl/`. Para produção, substitua os arquivos gerados pelos certificados oficiais do campus antes de prosseguir.

### 4. Configurar Permissões de Pastas e Diretórios Críticos
Garanta que os diretórios de logs e uploads possuam permissões adequadas para o container web:

```bash
mkdir -p logs uploads/profile
chmod -R 755 logs uploads
sudo chown -R 33:33 logs uploads  # www-data (ID 33) no Debian/Ubuntu
```

### 5. Subir os Containers com Docker Compose
Execute o Docker Compose em modo detach para construir e iniciar os serviços:

```bash
docker-compose up -d --build
```

Para verificar se todos os containers estão rodando corretamente (`ifsentral_web`, `ifsentral_worker`, `ifsentral_db`, `ifsentral_mqtt`):

```bash
docker-compose ps
```

### 6. Executar a Verificação de Saúde do Sistema
O IFSentral possui um script automatizado de diagnóstico. Como o PHP roda apenas dentro dos containers (não é pré-requisito no host), execute-o via `docker-compose exec` para validar a conexão com o banco, integridade do schema e pastas:

```bash
docker-compose exec web php system-check.php
```

### 7. Configurar as Tabelas de Segurança (Execução Única)
Após o primeiro deploy, crie as tabelas auxiliares usadas pelo rate limiting e pelas configurações de segurança da API. Este passo só precisa ser executado uma vez:

```bash
docker-compose exec web php setup-security-tables.php
```

### 8. Acessar a Plataforma
Abra no navegador o endereço configurado em `APP_URL` no `.env` (ex: `https://ifsentral.seu-campus.edu.br` ou `https://<IP-do-servidor>`). Caso esteja usando o certificado autoassinado gerado no Passo 3, o navegador exibirá um aviso de segurança até que os certificados oficiais sejam instalados.

---

## 🔌 Configuração e Segurança do MQTT

O broker Mosquitto utiliza autenticação baseada em credenciais por dispositivo e regras ACL (Access Control List).

1. **Gerar Credenciais para Dispositivos:**
   Sempre que um novo dispositivo for cadastrado na plataforma, suas credenciais MQTT devem ser geradas:
   ```bash
   php src/mqtt/generate_mqtt_credentials.php
   ```
2. **Gerar Arquivo de Senhas do Mosquitto:**
   ```bash
   php src/mqtt/generate_mosquitto_passwd.php
   php src/mqtt/generate_mosquitto_acl.php
   ```
3. **Conexão criptografada (TLS):** o broker aceita conexões TLS na porta `8883`, usando os mesmos certificados do `setup-ssl.sh`. A porta `1883` (texto puro) continua disponível para não quebrar dispositivos já configurados, mas para qualquer device fora da rede local/confiável (ex: conectando pela internet), configure-o para usar `8883` em vez de `1883`. Como os certificados são autoassinados por padrão, o firmware do dispositivo precisa ser configurado para confiar nesse certificado específico (ou, em bibliotecas de teste, desabilitar a verificação — não recomendado em produção).

---

## 📊 Manutenção e Monitoramento

* **Logs do MQTT Subscriber:**
  Os logs do worker que escuta os sensores em tempo real ficam salvos em:
  ```bash
  tail -f logs/mqtt_subscriber.log
  ```
* **Reiniciar os Serviços:**
  Caso precise reiniciar a aplicação após atualizações:
  ```bash
  docker-compose restart
  ```
* **Backup do Banco de Dados:**
  Recomenda-se configurar um cron job diário para backup do MySQL:
  ```bash
  docker exec -it ifsentral_db mysqldump -u ifsentral_user -p'sua_senha' ifsentral_bd > /opt/backups/ifsentral_$(date +%F).sql
  ```

---

## 🧪 Testes Automatizados

O projeto tem uma suíte PHPUnit (unitária + integração com SQLite em memória, sem precisar de MySQL) cobrindo a lógica de autenticação, autorização e 2FA. Para rodar localmente (fora dos containers, com PHP e Composer instalados):

```bash
composer install
composer test
```

Os testes ficam em `tests/Unit` e `tests/Integration` — ver `phpunit.xml` para a configuração. Isso valida lógica isolada (TOTP, CSRF, validação de payload, checagens de acesso do `AuthMiddleware`); não substitui testar o fluxo completo pela interface antes de publicar uma mudança em produção.

---

## 📞 Suporte e Contato
Em caso de dúvidas técnicas na implantação, consulte os logs em `logs/` ou abra um chamado técnico com a equipe de desenvolvimento do IFSentral.

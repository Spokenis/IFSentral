# IFSentral — Plataforma IoT Smart Campus

O **IFSentral** é uma plataforma integrada de Internet das Coisas (IoT) desenvolvida para gerenciar projetos, dispositivos de hardware (sensores, ESP32, LoRaWAN via TTN) e visualização de dados em tempo real (gráficos avançados e telemetria) em ambiente de campus inteligente.

Este guia é destinado à **Equipe de Tecnologia da Informação (TI)** do campus para orientar a implantação da aplicação em um servidor local da instituição.

---

## 🏗️ Arquitetura do Sistema

O sistema foi containerizado utilizando **Docker** e **Docker Compose**, garantindo isolamento, facilidade de manutenção e reproducibilidade. A stack é composta por:
1. **Web Server (Apache + PHP 8.x):** Responsável por servir a interface web (AdminLTE) e as APIs REST de gerenciamento e ingestão HTTP.
2. **Banco de Dados (MySQL 8.0):** Armazena usuários, projetos, permissões, chaves de API e históricos de payloads.
3. **Broker MQTT (Eclipse Mosquitto):** Gerencia a mensageria MQTT para os dispositivos de hardware com autenticação e ACLs restritas.
4. **MQTT Subscriber (Worker PHP em Background):** Consome as mensagens MQTT do broker e injeta os payloads no banco de dados em tempo real.

---

## ⚙️ Pré-requisitos de Infraestrutura

Antes de iniciar a instalação no servidor local (recomenda-se **Ubuntu Server 22.04 LTS** ou superior), certifique-se de que o servidor possui:
* **Docker** (versão 24.0+)
* **Docker Compose** (versão 2.20+)
* **Portas livres na rede/firewall:**
  * `80` (HTTP) / `443` (HTTPS) — Acesso web à plataforma.
  * `1883` (MQTT) — Comunicação dos dispositivos IoT com o broker.
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
* `ENABLE_EMAIL_FEATURES`: Defina como `true` caso possuam servidor SMTP configurado, ou `false` para cadastro de usuários com ativação automática imediata.

### 3. Configurar Permissões de Pastas e Diretórios Críticos
Garanta que os diretórios de logs e uploads possuam permissões adequadas para o container web:

```bash
mkdir -p logs uploads/profile
chmod -R 755 logs uploads
sudo chown -R 33:33 logs uploads  # www-data (ID 33) no Debian/Ubuntu
```

### 4. Subir os Containers com Docker Compose
Execute o Docker Compose em modo detach para construir e iniciar os serviços:

```bash
docker-compose up -d --build
```

Para verificar se todos os containers (`ifsentral_web`, `ifsentral_db`, `ifsentral_mqtt`) estão rodando corretamente:

```bash
docker-compose ps
```

### 5. Executar a Verificação de Saúde do Sistema
O IFSentral possui um script automatizado de diagnóstico. Execute-o para validar a conexão com o banco, integridade do schema e pastas:

```bash
php system-check.php
```

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

## 📞 Suporte e Contato
Em caso de dúvidas técnicas na implantação, consulte os logs em `logs/` ou abra um chamado técnico com a equipe de desenvolvimento do IFSentral.

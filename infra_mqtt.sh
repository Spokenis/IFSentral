#!/bin/bash

# ==============================================================================
# Script de Provisionamento Automático - Infraestrutura MQTT (IFSentral)
# DEVE SER EXECUTADO COMO ROOT (sudo)
# ==============================================================================

# Aborta o script caso algum comando falhe
set -e

# Variáveis do Projeto
PROJECT_DIR="/var/www/html/IFSentral"
PHP_USER="www-data" # Usuário padrão do Apache/Nginx no Ubuntu/Debian
MOSQUITTO_CONF_DIR="/etc/mosquitto/conf.d"

echo "=== 1. Verificando privilégios ==="
if [ "$EUID" -ne 0 ]; then
  echo "ERRO: Este script precisa ser executado como root (sudo)."
  exit 1
fi

echo "=== 2. Instalando Dependências do Sistema ==="
apt-get update
apt-get install -y mosquitto mosquitto-clients php-cli

echo "=== 3. Configurando Segurança do Mosquitto ==="
# Cria a configuração principal travando o acesso anônimo
cat <<EOF > /etc/mosquitto/mosquitto.conf
pid_file /run/mosquitto/mosquitto.pid
persistence true
persistence_location /var/lib/mosquitto/
log_dest file /var/log/mosquitto/mosquitto.log
include_dir /etc/mosquitto/conf.d

listener 1883
allow_anonymous false
password_file $MOSQUITTO_CONF_DIR/passwd
acl_file $MOSQUITTO_CONF_DIR/acl.acl
EOF

# Cria os arquivos vazios e ajusta permissões para que o PHP consiga sobrescrevê-los
touch $MOSQUITTO_CONF_DIR/passwd
touch $MOSQUITTO_CONF_DIR/acl.acl
chown $PHP_USER:$PHP_USER $MOSQUITTO_CONF_DIR/passwd
chown $PHP_USER:$PHP_USER $MOSQUITTO_CONF_DIR/acl.acl
chmod 644 $MOSQUITTO_CONF_DIR/passwd
chmod 644 $MOSQUITTO_CONF_DIR/acl.acl

echo "=== 4. Orquestrando o Worker PHP (Systemd) ==="
# Cria o serviço do Systemd para manter o worker rodando 24/7
cat <<EOF > /etc/systemd/system/mqtt-worker.service
[Unit]
Description=Worker MQTT do IFSentral
After=network.target mosquitto.service mysql.service

[Service]
Type=simple
User=$PHP_USER
WorkingDirectory=$PROJECT_DIR/src/mqtt
ExecStart=/usr/bin/php $PROJECT_DIR/src/mqtt/mqtt_subscriber.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

# Recarrega o daemon do Linux para reconhecer o novo serviço
systemctl daemon-reload
systemctl enable mqtt-worker

echo "=== 5. Configurando Automação (Cronjob) ==="
# Adiciona a rotina de sincronização no crontab do usuário do servidor web
CRON_JOB="*/5 * * * * /usr/bin/php $PROJECT_DIR/src/mqtt/sync_mosquitto.php > /dev/null 2>&1"
(crontab -u $PHP_USER -l 2>/dev/null | grep -v "sync_mosquitto.php"; echo "$CRON_JOB") | crontab -u $PHP_USER -

echo "=== 6. Iniciando os Motores ==="
# Reinicia o Mosquitto com a nova trava
systemctl restart mosquitto

# Roda o sincronizador uma vez manualmente para gerar as senhas reais do banco
echo "Gerando chaves e ACLs iniciais..."
sudo -u $PHP_USER php $PROJECT_DIR/src/mqtt/sync_mosquitto.php || true

# Dá reload no Mosquitto para ler os arquivos gerados
systemctl reload mosquitto

# Inicia o worker
systemctl start mqtt-worker

echo "========================================================================="
echo "✅ DEPLOY CONCLUÍDO COM SUCESSO!"
echo "Mosquitto:      Travado e operando na porta 1883"
echo "Worker PHP:     Rodando em background gerenciado pelo Systemd"
echo "Sincronização:  Agendada a cada 5 minutos via Cron"
echo ""
echo "Para ver os logs do worker em tempo real, use:"
echo "sudo journalctl -u mqtt-worker -f"
echo "========================================================================="
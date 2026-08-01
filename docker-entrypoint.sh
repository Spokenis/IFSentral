#!/bin/bash
# docker-entrypoint.sh - Script de inicialização para o container web/worker

set -e

MQTT_PASSWD_FILE="/mosquitto/config/passwords.txt"
MQTT_ACL_FILE="/mosquitto/config/acl.acl"
PHP_DIR="/var/www/html"

wait_for_mysql() {
    local host="${DB_HOST:-db}"
    local port="3306"
    local timeout=60

    echo "[ENTRYPOINT] Aguardando MySQL em $host:$port..."
    local start_time=$(date +%s)

    while true; do
        if nc -z "$host" "$port" 2>/dev/null; then
            echo "[ENTRYPOINT] MySQL está pronto!"
            return 0
        fi

        local current_time=$(date +%s)
        local elapsed=$((current_time - start_time))

        if [[ $elapsed -ge $timeout ]]; then
            echo "[ENTRYPOINT] ERRO: Timeout ao aguardar MySQL ($timeout segundos)"
            return 1
        fi

        sleep 2
    done
}

generate_mqtt_credentials() {
    echo "[ENTRYPOINT] Gerando credenciais MQTT..."
    mkdir -p "$(dirname "$MQTT_PASSWD_FILE")"

    if command -v php &> /dev/null && [[ -f "$PHP_DIR/src/mqtt/generate_mosquitto_passwd.php" ]]; then
        echo "[ENTRYPOINT] Gerando senhas via PHP..."
        php "$PHP_DIR/src/mqtt/generate_mosquitto_passwd.php" 2>/dev/null || true
        php "$PHP_DIR/src/mqtt/generate_mosquitto_acl.php" 2>/dev/null || true
    fi

    if [[ ! -s "$MQTT_PASSWD_FILE" ]]; then
        echo "[ENTRYPOINT] Criando credenciais padrão..."
        local admin_user="${MQTT_USERNAME:-admin}"
        local admin_pass="${MQTT_PASSWORD:-$(openssl rand -base64 12 2>/dev/null || echo "ifsentral_admin_2024")}"

        if command -v mosquitto_passwd &> /dev/null; then
            touch /tmp/mqtt_creds.txt
            mosquitto_passwd -b /tmp/mqtt_creds.txt "$admin_user" "$admin_pass"
            cp /tmp/mqtt_creds.txt "$MQTT_PASSWD_FILE"
            rm -f /tmp/mqtt_creds.txt
        else
            echo "[ENTRYPOINT] ERRO CRÍTICO: mosquitto_passwd não encontrado! Impossível gerar hash compatível."
            exit 1
        fi

        echo "[ENTRYPOINT] Credenciais MQTT criadas em $MQTT_PASSWD_FILE"
        echo "[ENTRYPOINT] Usuário: $admin_user configurado com segurança."
    fi

    if [[ ! -s "$MQTT_ACL_FILE" ]]; then
        echo "[ENTRYPOINT] Criando ACL padrão..."
        cat <<EOF > "$MQTT_ACL_FILE"
# ACL - Mosquitto
topic readwrite \$SYS/broker/#

user ${MQTT_USERNAME:-admin}
topic readwrite #
EOF
        echo "[ENTRYPOINT] ACL padrão criada em $MQTT_ACL_FILE"
    fi

    chmod 644 "$MQTT_PASSWD_FILE" 2>/dev/null || true
    chmod 644 "$MQTT_ACL_FILE" 2>/dev/null || true
    chown 1883:1883 "$MQTT_PASSWD_FILE" 2>/dev/null || true
    chown 1883:1883 "$MQTT_ACL_FILE" 2>/dev/null || true

    echo "[ENTRYPOINT] Credenciais MQTT configuradas!"
}

echo "[ENTRYPOINT] Inicializando container IFSentral..."

if [[ "$ROLE" == "worker" ]]; then
    wait_for_mysql
    generate_mqtt_credentials
    echo "[ENTRYPOINT] Iniciando worker MQTT..."
    exec php /var/www/html/src/mqtt/mqtt_subscriber.php
fi

if [[ "$ROLE" == "web" ]]; then
    echo "[ENTRYPOINT] Iniciando servidor web..."
    exec apache2-foreground
fi

echo "[ENTRYPOINT] Executando comando: $@"
exec "$@"
#!/bin/bash
# mosquitto-entrypoint.sh - Script de inicialização personalizado para Mosquitto

set -e

MQTT_PASSWD_FILE="/mosquitto/config/passwords.txt"
MQTT_ACL_FILE="/mosquitto/config/acl.acl"

echo "[MOSQUITTO-ENTRYPOINT] Inicializando configuração do Mosquitto..."

mkdir -p "$(dirname "$MQTT_PASSWD_FILE")"

if [[ ! -f "$MQTT_PASSWD_FILE" || ! -s "$MQTT_PASSWD_FILE" ]]; then
    echo "[MOSQUITTO-ENTRYPOINT] Arquivo de senhas não encontrado. Criando padrão..."
    local admin_user="${MQTT_USERNAME:-admin}"
    local admin_pass="${MQTT_PASSWORD:-ifsentral_admin_2024}"

    mosquitto_passwd -c -b "$MQTT_PASSWD_FILE" "$admin_user" "$admin_pass"
    echo "[MOSQUITTO-ENTRYPOINT] Usuário '$admin_user' criado com sucesso via PBKDF2."
fi

if [[ ! -f "$MQTT_ACL_FILE" || ! -s "$MQTT_ACL_FILE" ]]; then
    echo "[MOSQUITTO-ENTRYPOINT] Criando ACL padrão..."
    cat <<EOF > "$MQTT_ACL_FILE"
# ACL - Mosquitto
user ${MQTT_USERNAME:-admin}
topic readwrite #

pattern readwrite mqtt/projects/%u/#
EOF
fi

chown mosquitto:mosquitto "$MQTT_PASSWD_FILE" 2>/dev/null || chown 1883:1883 "$MQTT_PASSWD_FILE" 2>/dev/null || true
chown mosquitto:mosquitto "$MQTT_ACL_FILE" 2>/dev/null || chown 1883:1883 "$MQTT_ACL_FILE" 2>/dev/null || true
chmod 644 "$MQTT_PASSWD_FILE" "$MQTT_ACL_FILE" 2>/dev/null || true

echo "[MOSQUITTO-ENTRYPOINT] Configuração concluída. Iniciando Mosquitto..."

exec /usr/sbin/mosquitto -c /mosquitto/config/mosquitto.conf
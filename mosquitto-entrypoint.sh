#!/bin/sh
# mosquitto-entrypoint.sh - POSIX shell entrypoint for Mosquitto

set -e

MQTT_PASSWD_FILE="/mosquitto/config/passwords.txt"
MQTT_ACL_FILE="/mosquitto/config/acl.acl"

echo "[MOSQUITTO-ENTRYPOINT] Inicializando configuração do Mosquitto..."

mkdir -p "$(dirname "$MQTT_PASSWD_FILE")"

if [ ! -f "$MQTT_PASSWD_FILE" ] || [ ! -s "$MQTT_PASSWD_FILE" ]; then
    echo "[MOSQUITTO-ENTRYPOINT] Arquivo de senhas não encontrado. Criando padrão..."
    admin_user="${MQTT_USERNAME:-admin}"
    admin_pass="${MQTT_PASSWORD:-ifsentral_admin_2024}"

    mosquitto_passwd -c -b "$MQTT_PASSWD_FILE" "$admin_user" "$admin_pass"
    echo "[MOSQUITTO-ENTRYPOINT] Usuário '$admin_user' criado com sucesso via PBKDF2."
fi

if [ ! -f "$MQTT_ACL_FILE" ] || [ ! -s "$MQTT_ACL_FILE" ]; then
    echo "[MOSQUITTO-ENTRYPOINT] Criando ACL padrão..."
    cat <<EOF > "$MQTT_ACL_FILE"
# ACL - Mosquitto
user ${MQTT_USERNAME:-admin}
topic readwrite #

pattern readwrite mqtt/projects/%u/#
EOF
fi

ajustar_permissoes() {
    chown mosquitto:mosquitto "$MQTT_PASSWD_FILE" 2>/dev/null || chown 1883:1883 "$MQTT_PASSWD_FILE" 2>/dev/null || true
    chown mosquitto:mosquitto "$MQTT_ACL_FILE" 2>/dev/null || chown 1883:1883 "$MQTT_ACL_FILE" 2>/dev/null || true
    # Ensure auth files are not world-readable — mosquitto 2.x will warn or refuse to load them
    chmod 0700 "$MQTT_PASSWD_FILE" "$MQTT_ACL_FILE" 2>/dev/null || true
}
ajustar_permissoes

# O MosquittoSync roda no Apache do contêiner web como www-data (gid 33) e
# substitui passwords.txt e acl.acl por mv. Para isso o diretório do volume
# precisa aceitar escrita desse grupo; os arquivos em si continuam 0700 do
# usuário mosquitto (ajustados pelo vigia abaixo após cada substituição).
WEB_GID="${MQTT_CONFIG_WRITER_GID:-33}"
CONFIG_DIR="$(dirname "$MQTT_PASSWD_FILE")"
chgrp "$WEB_GID" "$CONFIG_DIR" 2>/dev/null || true
chmod 0775 "$CONFIG_DIR" 2>/dev/null || true

echo "[MOSQUITTO-ENTRYPOINT] Configuração concluída. Iniciando Mosquitto..."

/usr/sbin/mosquitto -c /mosquitto/config/mosquitto.conf &
MOSQUITTO_PID=$!

# Repassa o sinal de parada do Docker ao broker
trap 'kill -TERM "$MOSQUITTO_PID" 2>/dev/null' TERM INT

assinatura() {
    cat "$MQTT_PASSWD_FILE" "$MQTT_ACL_FILE" 2>/dev/null | md5sum
}

# Vigia: quando o contêiner web reescreve as credenciais ou a ACL, devolve os
# arquivos ao usuário mosquitto e envia SIGHUP, que faz o broker relê-los sem
# derrubar as conexões existentes.
INTERVALO="${MQTT_RELOAD_INTERVAL:-2}"
(
    ultima="$(assinatura)"
    while kill -0 "$MOSQUITTO_PID" 2>/dev/null; do
        sleep "$INTERVALO"
        atual="$(assinatura)"
        if [ "$atual" != "$ultima" ]; then
            # Espera os arquivos pararem de mudar: senhas e ACL são gravadas em
            # dois passos, e recarregar entre eles aplicaria só metade da mudança
            sleep 1
            while [ "$(assinatura)" != "$atual" ]; do
                atual="$(assinatura)"
                sleep 1
            done
            ajustar_permissoes
            kill -HUP "$MOSQUITTO_PID" 2>/dev/null || true
            echo "[MOSQUITTO-ENTRYPOINT] Credenciais/ACL alteradas; broker recarregado (SIGHUP)."
            ultima="$atual"
        fi
    done
) &

set +e
wait "$MOSQUITTO_PID"
STATUS=$?
# Se o wait foi interrompido pelo trap, espera o broker terminar de fato
if kill -0 "$MOSQUITTO_PID" 2>/dev/null; then
    wait "$MOSQUITTO_PID"
    STATUS=$?
fi
exit "$STATUS"
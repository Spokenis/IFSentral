#!/bin/sh
# Usuários como no TTN: "{aplicação}@ttn" com a chave de API como senha, mais
# "publicador", usado pelo script de teste para simular uplinks (porta 1883)
set -e
SENHAS=/mosquitto/data/passwd
: > "$SENHAS"
for par in $(echo "$TTN_APPS" | tr ',' ' '); do
    mosquitto_passwd -b "$SENHAS" "${par%%:*}@ttn" "${par#*:}"
done
mosquitto_passwd -b "$SENHAS" publicador publicador
chown mosquitto:mosquitto "$SENHAS"
chmod 0700 "$SENHAS"
exec mosquitto -c /config/ttn_mqtt.conf

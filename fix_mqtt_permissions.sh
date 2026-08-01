#!/bin/bash
# Script para corrigir permissões dos arquivos de credenciais MQTT

echo "Corrigindo permissões dos arquivos MQTT..."

# Ajusta permissões no volume do Mosquitto (se executado no container ou com volume montado)
if [ -d "/mosquitto/config" ]; then
    chown -R 1883:1883 /mosquitto/config 2>/dev/null
    chmod 644 /mosquitto/config/passwords.txt /mosquitto/config/acl.acl 2>/dev/null
fi

# Ajusta permissões dos arquivos de backup locais na raiz do projeto (se aplicável)
chown www-data:www-data ./mqtt_credentials*.txt 2>/dev/null
chmod 600 ./mqtt_credentials*.txt 2>/dev/null

echo "Permissões ajustadas com sucesso!"
ls -la /mosquitto/config/ 2>/dev/null || ls -la ./mqtt_credentials*.txt 2>/dev/null
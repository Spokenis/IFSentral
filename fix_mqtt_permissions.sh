#!/bin/bash
# Script para corrigir permissões dos arquivos de credenciais MQTT

echo "Corrigindo permissões dos arquivos MQTT..."

# Mudar owner para www-data (usuário do PHP-FPM)
chown www-data:www-data /var/www/html/minha-api-php/mqtt_credentials_BACKUP_SEGURO.txt 2>/dev/null
chown www-data:www-data /var/www/html/minha-api-php/mqtt_credentials_backup.txt 2>/dev/null

# Garantir permissões de leitura
chmod 640 /var/www/html/minha-api-php/mqtt_credentials_BACKUP_SEGURO.txt 2>/dev/null
chmod 640 /var/www/html/minha-api-php/mqtt_credentials_backup.txt 2>/dev/null

echo "Permissões ajustadas!"
ls -lh /var/www/html/minha-api-php/mqtt_credentials*.txt

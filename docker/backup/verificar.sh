#!/bin/bash
# verificar.sh - Healthcheck do contêiner backup
#
# "healthy" enquanto o último backup bem-sucedido tiver menos de
# BACKUP_ALERTA_HORAS horas (padrão 26: um backup diário com folga). Se os
# backups pararem de dar certo, `docker compose ps` mostra "unhealthy".
limite=$(( ${BACKUP_ALERTA_HORAS:-26} * 3600 ))
agora=$(date +%s)
if [[ -f /backups/.ultimo-sucesso ]]; then
    ultimo=$(stat -c %Y /backups/.ultimo-sucesso)
else
    # Instalação nova: ainda não houve horário de backup
    ultimo=$(cat /tmp/iniciado 2>/dev/null || echo 0)
fi
(( agora - ultimo < limite ))

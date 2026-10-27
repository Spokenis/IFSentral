#!/bin/bash
# agendador.sh - Processo principal do contêiner backup
#
# Faz um backup em cada horário de BACKUP_HORARIOS (ex.: "03:00" ou
# "03:00 15:00"), no fuso TZ. Não precisa de cron no host.
set -uo pipefail

date +%s > /tmp/iniciado  # usado por verificar.sh antes do primeiro backup

HORARIOS="${BACKUP_HORARIOS:-03:00}"
for h in $HORARIOS; do
    date -d "today $h" +%s > /dev/null 2>&1 || { echo "BACKUP_HORARIOS inválido: '$h' (use HH:MM)"; exit 1; }
done

# docker stop: sai na hora em vez de esperar o fim do sleep
trap 'exit 0' TERM INT

echo "Backups agendados para: $HORARIOS ($(date +%Z)); mantidos por ${BACKUP_MANTER_DIAS:-14} dias"

while true; do
    agora=$(date +%s)
    proximo=""
    for h in $HORARIOS; do
        t=$(date -d "today $h" +%s)
        (( t <= agora )) && t=$(date -d "tomorrow $h" +%s)
        if [[ -z "$proximo" ]] || (( t < proximo )); then
            proximo=$t
        fi
    done
    echo "Próximo backup: $(date -d "@$proximo" '+%d/%m/%Y %H:%M')"
    sleep $(( proximo - agora )) &
    wait $!
    bash /backup/fazer-backup.sh auto || echo "O backup falhou; veja backup.log na pasta de backups"
done

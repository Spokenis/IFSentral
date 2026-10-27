#!/usr/bin/env bash
# restaurar-backup.sh - Restaura um backup do banco (e das fotos de perfil)
#
#   sudo manutencao/restaurar-backup.sh                 # lista os backups
#   sudo manutencao/restaurar-backup.sh ARQUIVO.sql.gz  # restaura este
#
# Antes de restaurar, faz um backup de segurança do estado atual (rótulo
# antes-restauracao), para poder desfazer. O worker MQTT e a ponte TTN ficam
# parados durante a restauração para não gravarem dados no meio dela; o site
# continua no ar, mas pode mostrar dados incompletos por alguns segundos.
set -euo pipefail
cd "$(dirname "$0")/.."

# </dev/null: o docker compose exec lê a entrada padrão e engoliria a
# confirmação digitada (ou enviada por pipe) antes do read
dc() { docker compose "$@" < /dev/null; }

if [[ $# -eq 0 ]]; then
    echo "Backups disponíveis (mais novos por último):"
    dc exec -T backup bash -c 'ls -1tr /backups/*.sql.gz 2>/dev/null | while read -r f; do echo "  $(basename "$f")  ($(du -h "$f" | cut -f1))"; done'
    echo
    echo "Para restaurar: sudo $0 NOME_DO_ARQUIVO.sql.gz"
    exit 0
fi

ARQUIVO=$(basename "$1")
dc exec -T backup test -f "/backups/$ARQUIVO" || { echo "Backup não encontrado: $ARQUIVO"; exit 1; }

echo "Isto vai SUBSTITUIR o banco de dados atual pelo backup $ARQUIVO."
read -r -p "Digite RESTAURAR para continuar: " resposta || resposta=""
[[ "$resposta" == RESTAURAR ]] || { echo "Cancelado: nada foi alterado."; exit 1; }

echo "==> Backup de segurança do estado atual"
dc exec -T backup bash /backup/fazer-backup.sh antes-restauracao

echo "==> Parando o worker MQTT e a ponte TTN"
dc stop worker ttn-bridge

# Volta com o worker e a ponte mesmo se a restauração falhar
trap 'echo "==> Iniciando o worker MQTT e a ponte TTN"; dc start worker ttn-bridge' EXIT

dc exec -T backup bash /backup/restaurar.sh "$ARQUIVO"

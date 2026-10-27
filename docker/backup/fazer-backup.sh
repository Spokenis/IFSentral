#!/bin/bash
# fazer-backup.sh - Faz um backup agora (roda no contêiner backup)
#
#   docker compose exec backup bash /backup/fazer-backup.sh [rótulo]
#
# Gera em /backups (pasta BACKUP_DIR do host):
#   ifscentral_AAAA-MM-DD_HHMMSS_<rótulo>.sql.gz          banco de dados
#   ifscentral_AAAA-MM-DD_HHMMSS_<rótulo>.uploads.tar.gz  fotos de perfil
# e apaga os backups com mais de BACKUP_MANTER_DIAS dias.
set -euo pipefail
umask 077  # o dump tem hashes de senha e chaves de API: só o root do host lê

DESTINO=/backups
ROTULO="${1:-manual}"
BASE="$DESTINO/ifscentral_$(date +%Y-%m-%d_%H%M%S)_${ROTULO}"
LOG="$DESTINO/backup.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

falhou() {
    rm -f "$BASE".*.tmp
    log "ERRO: backup ($ROTULO) falhou na linha $1"
    exit 1
}
trap 'falhou $LINENO' ERR

log "Iniciando backup ($ROTULO)"

# --single-transaction: cópia consistente das tabelas InnoDB sem travar o site
# (as escritas continuam durante o dump)
MYSQL_PWD="$DB_PASS" mysqldump -h "$DB_HOST" -u "$DB_USER" \
    --single-transaction --routines --triggers --no-tablespaces \
    --default-character-set=utf8mb4 "$DB_NAME" \
    | gzip -6 > "$BASE.sql.gz.tmp"

# Um dump interrompido não termina com esta linha
gzip -t "$BASE.sql.gz.tmp"
zcat "$BASE.sql.gz.tmp" | tail -n 1 | grep -q '^-- Dump completed' || {
    log "ERRO: o dump do banco ficou incompleto"
    false
}
mv "$BASE.sql.gz.tmp" "$BASE.sql.gz"

if [[ -d /dados/uploads ]]; then
    tar -czf "$BASE.uploads.tar.gz.tmp" -C /dados uploads
    mv "$BASE.uploads.tar.gz.tmp" "$BASE.uploads.tar.gz"
fi

touch "$DESTINO/.ultimo-sucesso"
log "Backup concluído: $(basename "$BASE").sql.gz ($(du -h "$BASE.sql.gz" | cut -f1))"

# Rotação: só depois de um backup bem-sucedido, para nunca ficar sem nenhum
MANTER="${BACKUP_MANTER_DIAS:-14}"
find "$DESTINO" -maxdepth 1 -name 'ifscentral_*' -type f -mmin +$((MANTER * 1440)) -print -delete \
    | while read -r antigo; do log "Removido (mais de $MANTER dias): $(basename "$antigo")"; done

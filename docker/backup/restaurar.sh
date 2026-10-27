#!/bin/bash
# restaurar.sh - Restaura um backup (roda no contêiner backup)
#
# Não chame direto: use manutencao/restaurar-backup.sh no host, que faz um
# backup de segurança antes e para o worker e a ponte TTN durante a restauração.
set -euo pipefail

ARQUIVO="/backups/$(basename "$1")"
[[ -f "$ARQUIVO" && "$ARQUIVO" == *.sql.gz ]] || { echo "Backup não encontrado: $1"; exit 1; }
gzip -t "$ARQUIVO"

echo "Restaurando o banco a partir de $(basename "$ARQUIVO")..."
# O dump recria cada tabela (DROP TABLE IF EXISTS + CREATE + INSERT)
zcat "$ARQUIVO" | MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME"

UPLOADS="${ARQUIVO%.sql.gz}.uploads.tar.gz"
if [[ -f "$UPLOADS" && -d /dados/uploads ]]; then
    echo "Restaurando os uploads..."
    find /dados/uploads -mindepth 1 -delete
    tar -xzf "$UPLOADS" -C /dados
fi

echo "Restauração concluída."

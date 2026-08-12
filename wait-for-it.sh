#!/bin/bash
# wait-for-it.sh - Aguarda um host:porta ficar disponível
# Uso: ./wait-for-it.sh host:port [-t timeout] [-- comando args...]

set -e

TIMEOUT=30
HOST=""
PORT=""
CMD=""

usage() {
    echo "Uso: $0 host:port [-t timeout] [-- comando args...]"
    exit 1
}

# Parse arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        *:* )
            HOST=$(echo "$1" | cut -d: -f1)
            PORT=$(echo "$1" | cut -d: -f2)
            shift
            ;;
        -t )
            TIMEOUT="$2"
            shift 2
            ;;
        -- )
            shift
            CMD="$*"
            break
            ;;
        * )
            usage
            ;;
    esac
done

if [[ -z "$HOST" || -z "$PORT" ]]; then
    usage
fi

echo "Aguardando $HOST:$PORT ficar disponível (timeout: ${TIMEOUT}s)..."

START_TIME=$(date +%s)
while true; do
    if nc -z "$HOST" "$PORT" 2>/dev/null; then
        echo "$HOST:$PORT está disponível!"
        break
    fi

    CURRENT_TIME=$(date +%s)
    ELAPSED=$((CURRENT_TIME - START_TIME))

    if [[ $ELAPSED -ge $TIMEOUT ]]; then
        echo "Timeout de ${TIMEOUT}s atingido ao aguardar $HOST:$PORT"
        exit 1
    fi

    sleep 1
done

# Executa comando se fornecido
if [[ -n "$CMD" ]]; then
    exec $CMD
fi


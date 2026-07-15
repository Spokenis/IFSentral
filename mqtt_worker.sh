#!/bin/bash
# mqtt_worker.sh - Script para gerenciar o worker MQTT

set -e

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( dirname "$SCRIPT_DIR" )"
MQTT_SUBSCRIBER="$PROJECT_ROOT/src/mqtt/mqtt_subscriber.php"
PID_FILE="$PROJECT_ROOT/.mqtt_worker.pid"
LOG_FILE="$PROJECT_ROOT/logs/mqtt_worker.log"
STATUS_FILE="$PROJECT_ROOT/.mqtt_worker_running"

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Função para imprimir mensagens
log() {
    echo -e "${GREEN}[MQTT Worker]${NC} $1"
}

error() {
    echo -e "${RED}[MQTT Worker ERROR]${NC} $1" >&2
}

warn() {
    echo -e "${YELLOW}[MQTT Worker WARN]${NC} $1"
}

# Função para iniciar o worker
start() {
    if [ -f "$STATUS_FILE" ]; then
        warn "Worker já está em execução (PID: $(cat $STATUS_FILE))"
        return 1
    fi

    log "Iniciando MQTT Worker..."
    
    # Cria diretório de logs se não existir
    mkdir -p "$(dirname "$LOG_FILE")"

    # Inicia o subscriber em background
    php "$MQTT_SUBSCRIBER" >> "$LOG_FILE" 2>&1 &
    WORKER_PID=$!
    
    # Aguarda um pouco para verificar se o processo iniciou corretamente
    sleep 2
    
    if kill -0 $WORKER_PID 2>/dev/null; then
        echo $WORKER_PID > "$STATUS_FILE"
        log "Worker iniciado com sucesso (PID: $WORKER_PID)"
        log "Logs em: $LOG_FILE"
        return 0
    else
        error "Falha ao iniciar o worker"
        return 1
    fi
}

# Função para parar o worker
stop() {
    if [ ! -f "$STATUS_FILE" ]; then
        warn "Worker não está em execução"
        return 1
    fi

    WORKER_PID=$(cat "$STATUS_FILE")
    
    if kill -0 $WORKER_PID 2>/dev/null; then
        log "Parando MQTT Worker (PID: $WORKER_PID)..."
        kill $WORKER_PID
        
        # Aguarda o processo terminar (máximo 10 segundos)
        for i in {1..10}; do
            if ! kill -0 $WORKER_PID 2>/dev/null; then
                rm -f "$STATUS_FILE"
                log "Worker parado com sucesso"
                return 0
            fi
            sleep 1
        done
        
        # Se ainda estiver rodando, força encerramento
        warn "Forçando encerramento do worker..."
        kill -9 $WORKER_PID 2>/dev/null || true
        rm -f "$STATUS_FILE"
        log "Worker parado à força"
    else
        warn "Processo não encontrado (PID: $WORKER_PID)"
        rm -f "$STATUS_FILE"
    fi
}

# Função para reiniciar o worker
restart() {
    log "Reiniciando MQTT Worker..."
    stop || true
    sleep 1
    start
}

# Função para verificar status
status() {
    if [ ! -f "$STATUS_FILE" ]; then
        echo -e "${RED}MQTT Worker não está em execução${NC}"
        return 1
    fi

    WORKER_PID=$(cat "$STATUS_FILE")
    
    if kill -0 $WORKER_PID 2>/dev/null; then
        echo -e "${GREEN}MQTT Worker está em execução (PID: $WORKER_PID)${NC}"
        echo "Logs:"
        tail -n 20 "$LOG_FILE"
        return 0
    else
        warn "PID inválido. Limpando..."
        rm -f "$STATUS_FILE"
        return 1
    fi
}

# Função para mostrar logs
logs() {
    if [ ! -f "$LOG_FILE" ]; then
        warn "Arquivo de log não encontrado"
        return 1
    fi
    
    if [ "$1" == "follow" ] || [ "$1" == "-f" ]; then
        tail -f "$LOG_FILE"
    else
        tail -n ${1:-50} "$LOG_FILE"
    fi
}

# Função para mostrar ajuda
help() {
    cat << EOF
Uso: ./mqtt_worker.sh [comando]

Comandos:
    start       - Inicia o worker MQTT
    stop        - Para o worker MQTT
    restart     - Reinicia o worker MQTT
    status      - Mostra o status do worker
    logs [n]    - Mostra os últimas n linhas do log (padrão: 50)
    logs follow - Mostra logs em tempo real (tail -f)
    help        - Mostra esta mensagem

Exemplos:
    ./mqtt_worker.sh start
    ./mqtt_worker.sh status
    ./mqtt_worker.sh logs 100
    ./mqtt_worker.sh logs follow
    ./mqtt_worker.sh stop
EOF
}

# Main
case "${1:-help}" in
    start)
        start
        ;;
    stop)
        stop
        ;;
    restart)
        restart
        ;;
    status)
        status
        ;;
    logs)
        logs "$2"
        ;;
    help)
        help
        ;;
    *)
        error "Comando desconhecido: $1"
        help
        exit 1
        ;;
esac

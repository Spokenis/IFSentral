#!/bin/bash
# deploy-production.sh - Script de deployment para produção
# Execute: bash deploy-production.sh

set -e  # Exit on error

BASEDIR=$(cd "$(dirname "$0")" && pwd)
LOG_FILE="$BASEDIR/deploy.log"

echo "" >> $LOG_FILE
echo "====== DEPLOY - $(date) ======" >> $LOG_FILE
echo ""
echo "🚀 INICIANDO DEPLOYMENT PARA PRODUÇÃO"
echo "=================================================="

# 1. Verificar permissões
echo ""
echo "1️⃣  Verificando permissões..."
if [[ $EUID -ne 0 ]]; then 
   echo "❌ Este script deve ser executado como root"
   exit 1
fi
echo "✅ Permissões OK"

# 2. Backup do banco
echo ""
echo "2️⃣  Fazendo backup do banco de dados..."
BACKUP_FILE="$BASEDIR/backups/ifsentral_$(date +%Y%m%d_%H%M%S).sql"
mkdir -p "$BASEDIR/backups"

# Ler variáveis do .env
if [ -f "$BASEDIR/src/config/.env" ]; then
    source "$BASEDIR/src/config/.env"
fi

DB_HOST=${DB_HOST:-localhost}
DB_USER=${DB_USER:-u145233873_root}
DB_PASS=${DB_PASS:-}
DB_NAME=${DB_NAME:-u145233873_ifsentral_bd}

if [ -z "$DB_PASS" ]; then
    mysqldump -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" > "$BACKUP_FILE"
else
    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE"
fi

echo "✅ Backup criado: $BACKUP_FILE"

# 3. Criar tabelas de segurança
echo ""
echo "3️⃣  Criando tabelas de segurança..."
cd "$BASEDIR"
php setup-security-tables.php >> $LOG_FILE 2>&1
echo "✅ Tabelas de segurança criadas"

# 4. Verificar sistema
echo ""
echo "4️⃣  Verificando saúde do sistema..."
php system-check.php | tail -10
echo "✅ Sistema verificado"

# 5. Definir permissões corretas
echo ""
echo "5️⃣  Definindo permissões..."
chmod 755 "$BASEDIR/logs"
chmod 755 "$BASEDIR/uploads"
chmod 600 "$BASEDIR/src/config/.env"
chmod 600 "$BASEDIR/mqtt_credentials_BACKUP_SEGURO.txt" 2>/dev/null || true
chown -R www-data:www-data "$BASEDIR/logs" 2>/dev/null || true
chown -R www-data:www-data "$BASEDIR/uploads" 2>/dev/null || true
echo "✅ Permissões configuradas"

# 6. Iniciar MQTT Worker
echo ""
echo "6️⃣  Iniciando MQTT Worker..."
pkill -f "mqtt_subscriber.php" || true
sleep 1
cd "$BASEDIR"
nohup php src/mqtt/mqtt_subscriber.php > logs/mqtt_subscriber.log 2>&1 &
MQTT_PID=$!
echo "$MQTT_PID" > .mqtt_worker.pid
echo "✅ MQTT Worker iniciado (PID: $MQTT_PID)"

# 7. Configurar cron job
echo ""
echo "7️⃣  Configurando cron job para health check..."
CRON_JOB="*/5 * * * * cd $BASEDIR && php src/mqtt/mqtt_health_check.php > /dev/null 2>&1"

# Remover cron job antigo se existir
crontab -u www-data -l 2>/dev/null | grep -v "mqtt_health_check" | crontab -u www-data - || true

# Adicionar novo cron job
(crontab -u www-data -l 2>/dev/null; echo "$CRON_JOB") | crontab -u www-data -
echo "✅ Cron job configurado"

# 8. Verificação final
echo ""
echo "8️⃣  Verificação final..."
sleep 2

if [ -f "$BASEDIR/.mqtt_worker.pid" ]; then
    MQTT_PID=$(cat "$BASEDIR/.mqtt_worker.pid")
    if kill -0 "$MQTT_PID" 2>/dev/null; then
        echo "✅ MQTT Worker está rodando"
    else
        echo "❌ MQTT Worker não está rodando!"
        exit 1
    fi
fi

# 9. Relatório
echo ""
echo "=================================================="
echo "✅ DEPLOYMENT COMPLETO COM SUCESSO!"
echo "=================================================="
echo ""
echo "📊 Resumo:"
echo "  • Backup: $BACKUP_FILE"
echo "  • MQTT Worker (PID): $MQTT_PID"
echo "  • Cron Job: configurado"
echo "  • Permissões: OK"
echo ""
echo "📝 Próximos passos:"
echo "  1. Verificar logs: tail -f logs/mqtt_subscriber.log"
echo "  2. Testar API: curl -X POST http://localhost/minha-api-php/src/api/enviar_payload.php"
echo "  3. Monitorar: watch -n 5 php system-check.php"
echo ""
echo "📋 Relatório de deploy: $LOG_FILE"
echo ""

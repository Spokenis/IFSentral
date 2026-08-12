#!/bin/bash

# Interrompe o script imediatamente se algum comando falhar
set -e

echo "===================================================="
echo "🚀 Iniciando a instalação automatizada do IFSentral"
echo "===================================================="

echo ""
echo "📦 1. Verificando e instalando dependências (Podman, Compose e DNS)..."
# O sudo pedirá sua senha aqui
sudo apt-get update
sudo apt-get install -y podman podman-compose aardvark-dns curl

echo ""
echo "⚙️ 2. Configurando as variáveis de ambiente (.env)..."
# Cria o diretório se não existir e gera o .env com as rotas corretas do Podman
mkdir -p src/config
cat <<EOF > src/config/.env
# Database Configuration
DB_HOST=db
DB_NAME=ifsentral_bd
DB_USER=ifsentral_user
DB_PASS=secretpassword

# MQTT Configuration
MQTT_HOST=mosquitto

# Application Configuration
APP_ENV=development

# Session Configuration
SESSION_SECURE=true
SESSION_HTTPONLY=true
SESSION_SAMESITE=Lax
EOF
echo "✅ Arquivo src/config/.env configurado com sucesso!"

echo ""
echo "🧹 3. Limpando redes e contêineres de execuções anteriores..."
# O '|| true' evita que o script pare se não houver nada para derrubar
podman-compose down || true
podman network prune -f

echo ""
echo "🏗️ 4. Construindo e levantando a infraestrutura..."
# O Podman fará o download das imagens, construirá o PHP e subirá os serviços
podman-compose up -d --build

echo ""
echo "===================================================="
echo "🎉 SUCESSO! A infraestrutura está rodando."
echo "===================================================="
echo "🌐 Acesse o painel pelo navegador (porta 8080 do contêiner):"
echo "👉 http://localhost:8080/src/pages/"
echo ""
echo "📝 Para monitorar os logs do worker MQTT em tempo real, digite:"
echo "👉 podman logs -f ifsentral_worker"
echo "===================================================="
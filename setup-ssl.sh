#!/bin/bash
# setup-ssl.sh - Configura diretórios e certificados SSL locais para o Docker / Produção
set -e

echo "🔒 Configurando diretórios e certificados SSL..."

# Criar diretórios necessários no host
sudo mkdir -p /etc/ifsentral
sudo mkdir -p /etc/ssl/certs
sudo mkdir -p /etc/ssl/private

# Verificar se os certificados SSL existem, senão gerar auto-assinados para teste/desenvolvimento
if [ ! -f /etc/ssl/certs/ifsentral-chain.crt ] || [ ! -f /etc/ssl/private/ifsentral.key ]; then
    echo "⚠️ Certificados SSL não encontrados em /etc/ssl/. Gerando certificados auto-assinados de contingência..."
    sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/ssl/private/ifsentral.key \
        -out /etc/ssl/certs/ifsentral-chain.crt \
        -subj "/C=BR/ST=Distrito Federal/L=Brasilia/O=IFSCentral/CN=ifsentral.online" \
        -addext "subjectAltName=DNS:ifsentral.online,DNS:localhost,IP:127.0.0.1${SSL_IP_EXTRA:+,IP:$SSL_IP_EXTRA}"
    # SAN: navegadores atuais ignoram o CN e exigem o nome/IP no subjectAltName
    # (o testssl de 09/11/2026 apontou a falta). Para incluir o IP do servidor:
    #   SSL_IP_EXTRA=10.50.0.30 ./setup-ssl.sh   (depois de apagar o certificado antigo)
    echo "✅ Certificados auto-assinados gerados com sucesso."
else
    echo "✅ Certificados SSL já existem no host."
fi

# Garantir permissões seguras
# A chave privada precisa ser legível também pelo container mosquitto (roda
# como uid/gid 1883, não root, diferente do container web/worker que inicia
# como root). group-owner 1883 + 640 dá acesso a ambos sem tornar a chave
# legível por qualquer usuário do host.
sudo chown root:1883 /etc/ssl/private/ifsentral.key
sudo chmod 640 /etc/ssl/private/ifsentral.key
sudo chmod 644 /etc/ssl/certs/ifsentral-chain.crt

echo "🎉 Configuração de SSL concluída com sucesso!"

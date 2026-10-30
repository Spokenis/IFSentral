#!/bin/sh
# Prepara e sobe o ambiente isolado dos testes E2E (pilha "ifs_e2e"):
#   - init/: schema de src/db/ifsentral_bd.sql SEM os dados (só roles e
#     api_settings), em dois bancos: ifsentral_bd (completa) e ifsentral_lite
#   - certs/: certificado autoassinado para o Apache e o Mosquitto
#   - lite/: cópia do IFSCentral Lite (o Apache do teste grava em uploads/)
#
# Uso:  sh preparar.sh        (sobe tudo)
#       docker compose -f compose.yml down   (derruba; dados do teste somem)
set -e
cd "$(dirname "$0")"
RAIZ=../..

mkdir -p init certs
python3 -I - "$RAIZ/IFSentral/src/db/ifsentral_bd.sql" init/01-schema.sql <<'EOF'
import sys, re
s = open(sys.argv[1], encoding='utf-8').read().split('\n', 1)[1]  # 1ª linha: "sandbox" do MariaDB
manter = {'roles', 'api_settings'}
s = re.sub(r"INSERT INTO `(\w+)` VALUES.*?;\n", lambda m: m.group(0) if m.group(1) in manter else '', s, flags=re.S)
open(sys.argv[2], 'w', encoding='utf-8').write(s)
EOF
{
    echo "CREATE DATABASE ifsentral_lite CHARACTER SET utf8mb4; GRANT ALL ON ifsentral_lite.* TO 'ifs'@'%'; USE ifsentral_lite;"
    cat init/01-schema.sql
} > init/02-lite.sql

if [ ! -f certs/cert.pem ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=localhost" \
        -keyout certs/key.pem -out certs/cert.pem 2>/dev/null
fi
# TTN simulado: CA de teste (instalada nos contêineres que acessam o "TTN") e
# certificado para eu1.cloud.thethings.network assinado por ela
if [ ! -f certs/ttn.pem ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=CA de teste IFSCentral" \
        -keyout certs/ca.key -out certs/ca.pem 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -subj "/CN=eu1.cloud.thethings.network" \
        -keyout certs/ttn.key -out certs/ttn.csr 2>/dev/null
    echo "subjectAltName=DNS:eu1.cloud.thethings.network" > certs/ttn.ext
    openssl x509 -req -in certs/ttn.csr -CA certs/ca.pem -CAkey certs/ca.key -CAcreateserial \
        -days 30 -extfile certs/ttn.ext -out certs/ttn.pem 2>/dev/null
fi
chmod 644 certs/*.pem certs/*.key

rm -rf lite && mkdir lite
(cd "$RAIZ/IFSentral_Lite/IFSentral" && tar --exclude=.git -cf - .) | tar -xf - -C lite

docker compose -f compose.yml up -d --build
docker exec ifs_e2e-lite-1 chown -R www-data:www-data /var/www/lite/uploads

echo "Completa: https://127.0.0.1:28443   Lite: http://127.0.0.1:28090   MQTT: 127.0.0.1:21883"
echo "TTN simulado: controle http://127.0.0.1:28444   MQTT (publicador) 127.0.0.1:21884"

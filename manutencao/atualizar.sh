#!/usr/bin/env bash
# atualizar.sh - Atualiza o IFSCentral sem tirar o site do ar
#
#   sudo manutencao/atualizar.sh              # git pull + atualização
#   sudo manutencao/atualizar.sh --sem-git    # usa o código que já está na pasta
#   sudo manutencao/atualizar.sh --sem-backup # pula o backup prévio (não recomendado)
#
# Passos: backup -> imagem nova (o site continua no ar) -> migrações do banco ->
# troca os dois contêineres web um de cada vez (o proxy atende pelo outro) ->
# troca o worker MQTT e a ponte TTN -> confere o site. Se um contêiner novo não
# ficar saudável, volta sozinho para a versão anterior.
#
# O banco (db) e o broker (mosquitto) não são reiniciados aqui: atualizá-los
# derruba conexões por alguns segundos e fica para um horário de pouco uso
# (ver README, "Atualizar o banco ou o broker").
set -euo pipefail
cd "$(dirname "$0")/.."

IMAGEM=ifsentral-app
USAR_GIT=1
FAZER_BACKUP=1
for arg in "$@"; do
    case "$arg" in
        --sem-git) USAR_GIT=0 ;;
        --sem-backup) FAZER_BACKUP=0 ;;
        *) echo "Opção desconhecida: $arg"; exit 2 ;;
    esac
done

dc() { docker compose "$@"; }
passo() { echo; echo "==> $*"; }
falha() { echo; echo "ERRO: $*" >&2; exit 1; }

# Espera o contêiner do serviço ficar "healthy" (ou, sem healthcheck,
# continuar rodando sem reiniciar por alguns segundos)
esperar() {
    local servico=$1 id estado
    id=$(dc ps -q "$servico")
    [[ -n "$id" ]] || return 1
    for _ in $(seq 1 60); do
        estado=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}/{{.RestartCount}}{{end}}' "$id")
        case "$estado" in
            healthy) return 0 ;;
            unhealthy|exited/*|dead/*) return 1 ;;
            running/0) sleep 5; [[ $(docker inspect -f '{{.State.Status}}/{{.RestartCount}}' "$id") == running/0 ]] && return 0 || return 1 ;;
        esac
        sleep 2
    done
    return 1
}

# Tira um contêiner web do proxy (ou devolve). O reload do nginx não derruba
# conexões: as requisições em andamento terminam, e as novas vão para o outro
# contêiner. Assim uma versão nova só recebe tráfego depois de saudável.
CONF_PROXY=/etc/nginx/conf.d/default.conf
tirar_do_proxy() {
    dc exec -T proxy sh -c "sed -i 's|server $1:80 resolve|server $1:80 down resolve|' $CONF_PROXY && nginx -s reload"
    sleep 3  # deixa terminar as requisições que já estavam nele
}
devolver_ao_proxy() {
    dc exec -T proxy sh -c "sed -i 's|server $1:80 down resolve|server $1:80 resolve|' $CONF_PROXY && nginx -s reload"
}

# Troca um contêiner web sem que ele receba tráfego antes de ficar saudável
trocar_web() {
    tirar_do_proxy "$1"
    dc up -d --no-deps --no-build "$1"
    if esperar "$1"; then
        devolver_ao_proxy "$1"
        return 0
    fi
    return 1
}

reverter() {
    echo
    echo "!!! $1 — voltando para a versão anterior"
    if [[ "${TEM_ANTERIOR:-0}" == 1 ]]; then
        docker tag "$IMAGEM:anterior" "$IMAGEM:latest"
        for s in web web-2; do
            trocar_web "$s" || echo "!!! $s também não ficou saudável na versão anterior"
        done
        dc up -d --no-deps --no-build worker ttn-bridge
        falha "a atualização foi desfeita; o sistema voltou à versão anterior. Veja: docker compose logs web"
    fi
    falha "não há versão anterior para voltar. Veja: docker compose logs web"
}

[[ $(dc ps --status running --services) == *proxy* ]] || falha "o proxy não está rodando.
Na primeira atualização para esta versão (que introduziu o proxy), rode uma vez:
    sudo docker compose up -d --build
O site fica alguns segundos fora só nessa vez; daí em diante use este script."

if [[ $USAR_GIT == 1 ]]; then
    passo "Baixando a versão nova (git pull)"
    git pull --ff-only
fi

if [[ $FAZER_BACKUP == 1 ]]; then
    passo "Backup antes de atualizar"
    dc exec -T backup bash /backup/fazer-backup.sh antes-atualizacao || falha "o backup falhou; nada foi alterado"
fi

passo "Construindo a imagem nova (o site continua no ar)"
TEM_ANTERIOR=0
if docker image inspect "$IMAGEM:latest" > /dev/null 2>&1; then
    docker tag "$IMAGEM:latest" "$IMAGEM:anterior"
    TEM_ANTERIOR=1
fi
dc build web

passo "Aplicando as migrações do banco"
dc run --rm --no-deps -T --entrypoint php web src/db/aplicar-migracoes.php || falha "a migração falhou; os contêineres em uso não foram trocados"

for servico in web web-2; do
    passo "Trocando $servico (o outro contêiner atende enquanto isso)"
    trocar_web "$servico" || reverter "$servico não ficou saudável"
done

passo "Trocando o worker MQTT e a ponte TTN"
# O worker retoma a sessão persistente no broker (nada se perde), e a ponte
# recupera pelo Storage do TTN o que chegou enquanto reiniciava
dc up -d --no-deps --no-build worker ttn-bridge
esperar worker || reverter "o worker MQTT não ficou rodando"
esperar ttn-bridge || reverter "a ponte TTN não ficou rodando"

passo "Recarregando o proxy (aplica mudanças em docker/nginx, sem derrubar conexões)"
# Gera de novo a configuração a partir do template; se a nova for inválida,
# mantém a atual
dc exec -T proxy sh -c "envsubst '\${HTTPS_PORT}' < /etc/nginx/templates/default.conf.template > /tmp/nova.conf \
    && cp $CONF_PROXY /tmp/atual.conf && cp /tmp/nova.conf $CONF_PROXY \
    && if nginx -t -q; then nginx -s reload; else cp /tmp/atual.conf $CONF_PROXY; exit 1; fi" \
    || echo "AVISO: a configuração nova do nginx é inválida; o proxy continua com a anterior"

passo "Conferindo o site pelo proxy"
porta=$(dc port proxy 443 | sed 's/.*://')
codigo=$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:${porta}/api/saude")
[[ "$codigo" == 200 ]] || reverter "o site respondeu HTTP $codigo pelo proxy"

docker image prune -f > /dev/null
echo
echo "Atualização concluída sem tirar o site do ar."
[[ $USAR_GIT == 1 ]] && echo "Versão: $(git log -1 --format='%h %s')"
exit 0

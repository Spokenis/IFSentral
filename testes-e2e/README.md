# Testes ponta a ponta (E2E)

`e2e_ifscentral.py` exercita a API do IFSCentral e do IFSCentral Lite pelas
mesmas rotas que o navegador usa, com dados válidos e inválidos, e confere o
código HTTP e o efeito no banco. As 19 seções cobrem: páginas e proteção de
rotas, cadastro, login e bloqueio por tentativas, CSRF, perfil e senha, foto,
2FA, pedido de perfil Moderador, projetos, participação (pedidos, convites,
limite de participantes), dispositivos, tags, mapeamentos, envio HTTP e TTN,
rate limit, gráficos, exportação, integração com o The Things Network
(Storage Integration; ponte MQTT só na completa), MQTT (só na completa),
administração e exclusões.

O ambiente inclui um **TTN simulado** (`ambiente/ttn_mock.py` e o broker
`ttn-mqtt`) que responde como `eu1.cloud.thethings.network` dentro da rede do
Docker, com certificado de uma CA de teste instalada nos contêineres — assim o
código testado é o mesmo de produção, sem opções só para teste.

**Rode só contra o ambiente de teste** — o script cria e apaga dados.

```sh
# 1. Sobe o ambiente isolado (contêineres ifs_e2e-*, portas 28443/28090/21883)
sh ambiente/preparar.sh

# 2. Dependências do script
python3 -m venv .venv && .venv/bin/pip install requests "paho-mqtt<2"

# 3. Edição completa (com MQTT e o teste de fila com o worker parado)
.venv/bin/python e2e_ifscentral.py --base https://127.0.0.1:28443 --edicao completa \
    --db-container ifs_e2e-db-1 --db-name ifsentral_bd \
    --mqtt-port 21883 --worker-container ifs_e2e-worker-1 \
    --ttn-mock http://127.0.0.1:28444 --ttn-mqtt-port 21884 \
    --ttn-bridge-container ifs_e2e-ttn-bridge-1 --json resultado-completa.json

# 4. Edição Lite
.venv/bin/python e2e_ifscentral.py --base http://127.0.0.1:28090 --edicao lite \
    --db-container ifs_e2e-db-1 --db-name ifsentral_lite \
    --ttn-mock http://127.0.0.1:28444 --json resultado-lite.json

# 5. Derruba o ambiente
docker compose -f ambiente/compose.yml down
```

O acesso direto ao banco (`docker exec ... mysql`) serve só para preparar o
cenário — promover o primeiro Admin, que o sistema não cria pela interface, e
limpar tentativas de login entre seções — e para conferir efeitos que a API
não expõe.

Não cobertos: fluxos de e-mail (verificação de cadastro e "esqueci a senha"),
porque o ambiente roda com `ENABLE_EMAIL_FEATURES=false`; o listener MQTT TLS
(8883); e o JavaScript das páginas, que é verificado só quanto a abrir sem
erro de PHP.

# Testes de carga — IFSCentral

`ifscentral_loadtest.py` gera carga contra o endpoint HTTP da plataforma
(`/api/enviar-payload`) e registra os dados necessários para **validar o
projeto** no capítulo de Resultados: taxa de sucesso, latência (média, mediana,
p90/p95/p99), throughput (req/s) e contagem de erros — por teste e por
dispositivo/projeto.

Funciona na edição **completa** e no **Lite** (ambos expõem o mesmo endpoint HTTP).

## Instalação

```bash
cd testes-carga
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

> `aiohttp` é obrigatório. `PyYAML` só é preciso se a config for `.yaml`
> (há também `config.example.json`, que não exige PyYAML).

## Cenário pronto: muitos projetos e dispositivos (`preparar_cenario.py`)

Cria N projetos com M dispositivos cada, pela API (como um usuário faria), e
grava as configs dos dois geradores com as chaves e credenciais MQTT reais.
Precisa de uma conta **Admin ou Moderator** sem 2FA:

```bash
pip install requests
python preparar_cenario.py --base https://IP:8443 --email admin@x --senha 'S3nha' \
    --projetos 20 --dispositivos 10 --mqtt-host IP --mqtt-port 1883
# -> config.cenario.json (HTTP) e config.mqtt.cenario.json (MQTT)
```

Os projetos se chamam "Carga 01", "Carga 02"... Para limpar depois, exclua-os
pela plataforma (Configurações do projeto > Excluir projeto).

## Configuração

Copie `config.example.yaml` (ou `.json`) para `config.yaml` e preencha cada
dispositivo. Um `target` = um dispositivo; podem ser de **projetos e até
servidores diferentes**.

```yaml
base_url: "http://192.168.0.50:8080"   # global (pode sobrescrever por target)
targets:
  - name: "ProjetoA - temperatura"
    device_id: 1
    api_key: "api_key_do_dispositivo"
    weight: 2                           # recebe ~2x mais requisicoes
    payload:
      temperatura: { type: float, min: 20, max: 35, decimals: 2 }
      umidade:     { type: int,   min: 40, max: 90 }
```

`device_id` e `api_key` vêm da página do dispositivo na plataforma.

**Tipos de campo do payload:** `float` (min/max/decimals), `int` (min/max),
`bool` (p = probabilidade de `true`), `choice` (values), `fixed` (value). Um
valor literal (ex.: `fixo: 42`) também é aceito.

## Como rodar

**Por número de requisições** (1000 no total, 50 simultâneas):
```bash
python ifscentral_loadtest.py --config config.yaml --requests 1000 --concurrency 50
```

**Por duração** (60 s, limitado a 100 req/s, 20 conexões simultâneas):
```bash
python ifscentral_loadtest.py --config config.yaml --duration 60 --rate 100 --concurrency 20
```

Interromper a qualquer momento com **Ctrl+C** — os resultados parciais são salvos.

### Parâmetros

| Flag | Descrição |
|------|-----------|
| `--config` | arquivo `.yaml`/`.json` (obrigatório) |
| `--requests N` | envia N requisições no total *(ou use `--duration`)* |
| `--duration S` | roda por S segundos *(exclui `--requests`)* |
| `--concurrency N` | requisições simultâneas (workers). Padrão 10 |
| `--rate R` | limite de R req/s (opcional; sem isso, vai no máximo) |
| `--timeout S` | timeout por requisição. Padrão 10 s |
| `--out DIR` | pasta de saída. Padrão `resultados/` |
| `--seed N` | semente aleatória (resultados reproduzíveis) |

## Saídas (para o TCC)

Cada execução grava, na pasta `--out`, dois arquivos com carimbo de data/hora:

- **`requests_AAAAMMDD-HHMMSS.csv`** — uma linha por requisição:
  `timestamp_utc, elapsed_s, target, device_id, status, latency_ms, ok, error`.
  Serve para gerar gráficos (latência ao longo do tempo, histograma, etc.).

- **`resumo_AAAAMMDD-HHMMSS.json`** — métricas agregadas: totais, taxa de
  sucesso, throughput, percentis de latência, status HTTP, erros e um
  detalhamento **por target** (projeto/dispositivo).

Exemplo de resumo impresso no terminal:

```
Requisicoes        : 1000
Sucesso / Falha    : 991 / 9  (99.1% ok)
Throughput         : 442.6 req/s
Latencia (ms)      : media 17.9 | mediana 5.6 | p95 7.5 | p99 1013 | max 1044
Status HTTP        : {'201': 991, '429': 9}
```

> **Dica para a validação:** rode baterias com concorrências crescentes
> (ex.: 1, 10, 50, 100) e compare throughput e p95/p99 — é o tipo de evidência
> que o capítulo de Resultados precisa, hoje ainda em aberto. Use `--seed` para
> os payloads serem reproduzíveis entre execuções.

## Gerador MQTT (`ifscentral_mqtt_loadtest.py`)

Mesma ideia, mas publicando no **broker MQTT** da plataforma (**só edição
completa** — o Lite não tem MQTT). Mede a latência de **PUBLISH → PUBACK (QoS 1)**,
ou seja, o tempo até o broker confirmar o recebimento.

```bash
pip install paho-mqtt
cp config.mqtt.example.yaml config.mqtt.yaml   # preencha device/senha MQTT
python ifscentral_mqtt_loadtest.py --config config.mqtt.yaml --requests 1000 --concurrency 20
```

Config (`config.mqtt.example.yaml`): um `broker` global (host/porta/tls) e uma
lista de `targets`. Cada target precisa de `project_id`, `device_id`,
`mqtt_username` (o usuário `mqdev_...` mostrado na página do dispositivo) e `mqtt_password`. Publica no tópico
`mqtt/projects/{project_id}/devices/{device_id}`.

- `--concurrency N` = N **conexões simultâneas** (cada uma é um cliente MQTT
  persistente). Como a autenticação MQTT é por dispositivo, **cada conexão usa
  um dispositivo**: com `--concurrency 10` só 10 dispositivos enviam. Para um
  cenário realista use `--concurrency` igual ao número de dispositivos — senão
  cada um passa do limite de 60 mensagens/min e o excedente é descartado pelo
  rate limiter (aparece como "Rate limit excedido" no log do worker).
- Porta `1883` (texto) ou `8883` (TLS, com `tls: true`). TLS de teste usa
  certificado self-signed sem validação.
- Saídas iguais às do HTTP: `mqtt_requests_*.csv` e `mqtt_resumo_*.json`.

> A latência MQTT medida é até o **broker**, não até o dado ser gravado no banco
> (isso depende do worker consumidor). **Sempre confira no banco** quantas
> mensagens foram gravadas (`SELECT COUNT(*) FROM device_payloads WHERE
> source='mqtt'` antes e depois): o PUBACK sai mesmo quando a mensagem é depois
> descartada pela fila do broker. Resultados de 08/11/2026 em
> `resultados/2026-11-08_pc-local/`. O throughput também depende da capacidade
> do broker, não só da ferramenta.

## Gerador de leitura (`ifscentral_leitura_loadtest.py`)

Os outros dois geradores só **enviam** dados. Este mede o lado da **leitura**,
como pessoas olhando os projetos e integrações consultando a API:

- `grafico` — `GET /api/obter-dados-grafico-renderizado?chart_id=N` com sessão
  logada (o que a página do projeto pede ao abrir e a cada 60 s; é a leitura
  mais pesada, porque agrega todas as leituras do período);
- `api` — `GET /api/buscar-payloads?device_id=N&limit=50` com a `X-Api-Key`.

```bash
python ifscentral_leitura_loadtest.py --config config.leitura.json --duration 60 --concurrency 10
python bateria.py --tool leitura --config config.leitura.json --levels 1,5,10,20 --duration 20
```

Config: `base_url`, `login` (`email`/`senha` de quem vê os projetos), lista de
ids em `graficos`, `dispositivos` (`device_id` + `api_key`) e, opcional, `mix`
(ex.: `{"grafico": 70, "api": 30}`). Saídas: `leitura_requests_*.csv` e
`leitura_resumo_*.json`, com percentis **por endpoint**.

Para medir **leitura e escrita ao mesmo tempo**, rode este junto com os
geradores HTTP/MQTT (em terminais separados ou em segundo plano) e compare a
latência de escrita com a de uma rodada só de escrita. Para a leitura ter
sentido, o banco precisa de histórico (dias de dados), não só dos minutos do
teste.

## Bateria de testes (`bateria.py`)

Roda o gerador (HTTP **ou** MQTT) em vários níveis de concorrência e consolida
tudo numa **tabela CSV** e num **gráfico PNG** (throughput × concorrência e
latência média/p95/p99 × concorrência) — pronto para o capítulo de Resultados.

```bash
pip install matplotlib

# HTTP: 1000 req por nível, níveis 1/10/50/100
python bateria.py --tool http --config config.yaml --levels 1,10,50,100 --requests 1000

# MQTT: 30 s por nível
python bateria.py --tool mqtt --config config.mqtt.yaml --levels 1,5,20,50 --duration 30
```

Gera em `resultados/bateria_<tool>_<data>/`:
- `bateria_consolidado.csv` — uma linha por nível (concorrência, throughput,
  taxa de sucesso, latência média/mediana/p95/p99/máx);
- `bateria_grafico.png` — os dois gráficos;
- `conc_<n>/` — os relatórios brutos de cada nível.

Use `--seed` para reprodutibilidade e `--rate` para limitar req/s por nível.

## Observações

- Rate limiting da plataforma: respostas `429` (HTTP) indicam que o rate limiter
  do IFSCentral barrou o dispositivo. Isso é um resultado válido a reportar (não
  um bug da ferramenta). Ajuste `--rate` ou os limites no painel de administração.
- Firmwares reais para ESP32/ESP8266/Arduino ficam em `../firmware-teste/`.

# Testes de carga — IFSCentral

`ifscentral_loadtest.py` gera carga contra o endpoint HTTP da plataforma
(`/api/enviar_payload.php`) e registra os dados necessários para **validar o
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
`mqtt_username` (`device_<id>`) e `mqtt_password`. Publica no tópico
`mqtt/projects/{project_id}/devices/{device_id}`.

- `--concurrency N` = N **conexões simultâneas** (cada uma é um cliente MQTT
  persistente). Como a autenticação MQTT é por dispositivo, as conexões são
  distribuídas entre os targets conforme o `weight`.
- Porta `1883` (texto) ou `8883` (TLS, com `tls: true`). TLS de teste usa
  certificado self-signed sem validação.
- Saídas iguais às do HTTP: `mqtt_requests_*.csv` e `mqtt_resumo_*.json`.

> A latência MQTT medida é até o **broker**, não até o dado ser gravado no banco
> (isso depende do worker consumidor). O throughput também depende da capacidade
> do broker, não só da ferramenta.

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

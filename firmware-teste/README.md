# Firmware de teste — IFSCentral

Sketches prontos para colocar em **ESP32**, **ESP8266** ou **Arduino (com Wi-Fi)**
e gerar dados de teste na plataforma IFSCentral.

## O que tem aqui

| Pasta | Placa | Protocolo | Serve para |
|-------|-------|-----------|------------|
| `esp32_http/` | ESP32 | HTTP (API REST) | IFSCentral **completo e Lite** |
| `esp32_mqtt/` | ESP32 | MQTT | **Só** o IFSCentral completo (o Lite não tem MQTT) |
| `esp8266_http/` | ESP8266 / NodeMCU / Wemos D1 | HTTP | completo e Lite |
| `esp32_estresse_http/` | ESP32 | HTTP | Teste de estresse: envia e lê o mais rápido possível (estatísticas a cada 5 s) |
| `esp32_estresse_mqtt/` | ESP32 | MQTT | Teste de estresse: publica o mais rápido possível e recebe de volta pelo broker |
| `arduino_wifinina_http/` | Arduino UNO WiFi Rev2 / Nano 33 IoT / MKR 1010 | HTTP | completo e Lite |

> **Qual usar?** Se você só quer ver dados aparecendo nos gráficos, o caminho
> **HTTP** é o mais simples e funciona nas duas edições. Use **MQTT** apenas se
> quer testar especificamente esse fluxo na edição completa.

## Como abrir

Cada sketch está numa pasta com o mesmo nome do arquivo `.ino` (exigência do
Arduino IDE). Abra o `.ino` direto no Arduino IDE.

## Bibliotecas necessárias (Gerenciador de Bibliotecas)

- **ArduinoJson** (Benoit Blanchon) — todos os sketches
- **PubSubClient** (Nick O'Leary) — só o `esp32_mqtt`
- **WiFiNINA** (Arduino) — só o `arduino_wifinina_http`

(No ESP32/ESP8266, `WiFi` e `HTTPClient` já vêm com o core da placa.)

## Onde pegar os valores de configuração

Entre na plataforma, abra o **projeto** → **dispositivo** e você encontra:

- **`DEVICE_ID`** — o ID numérico do dispositivo.
- **`PROJECT_ID`** — o ID do projeto: aparece ao lado do nome do projeto (com botão
  de copiar), em "Meus Projetos" e dentro do tópico MQTT de cada dispositivo
  (aba **Dispositivos** do projeto, ou na página do dispositivo). Só é usado no
  sketch MQTT.
- **`API_KEY`** — a chave de API do dispositivo (tem 32+ caracteres). Vai no
  header `X-Api-Key`. Usada nos sketches HTTP.
- **Credenciais MQTT** — usuário no formato `mqdev_` + 16 caracteres (**não** é
  `device_<DEVICE_ID>`) e a senha, ambos na seção MQTT da página do dispositivo,
  com o tópico já montado. Usadas só no sketch MQTT.

Se você não tem um dispositivo cadastrado ainda, crie um em **Meus Projetos →
(projeto) → Cadastrar dispositivo**.

## Rede do câmpus (login individual)

Nos sketches de ESP32, preencha `WIFI_EAP_USUARIO` com o seu usuário e
`WIFI_PASSWORD` com a sua senha para entrar numa rede WPA2-Enterprise (PEAP),
como a rede de alunos. Com `WIFI_EAP_USUARIO = ""` o sketch usa Wi-Fi comum.

## Endereços e portas

**HTTP** — preencha `BASE_URL` (ESP) ou `HOST`+`PORT` (Arduino):

- Stack Docker local de testes: `http://IP_DO_SERVIDOR:8080` (ou `https://...:8443`)
- Produção: `https://ifsentral.online`

**MQTT** — preencha `MQTT_HOST` e `MQTT_PORT`:

- `1883` → MQTT em texto puro (rede local) — `USAR_TLS = false`
- `8883` → MQTT sobre TLS (fora da rede local) — `USAR_TLS = true`

> **`rc=-2` no MQTT** = o ESP32 não conseguiu abrir conexão TCP com o broker.
> Não é usuário/senha errados (esses dão `rc=4`/`rc=5`). Em rede institucional
> quase sempre é a porta 1883/8883 bloqueada entre a sub-rede do dispositivo e
> a do servidor (teste no PC da mesma rede: `nc -zv IP 1883` ou, no Windows,
> `Test-NetConnection IP -Port 1883`). Liberar no `iptables` do servidor não
> resolve se o bloqueio é no roteador do câmpus; nesse caso peça a liberação à
> TI ou use o sketch **HTTP** com `https://IP:8443`, que passa pela mesma porta
> do navegador.

> Os certificados de teste são auto-assinados, por isso os sketches usam
> `setInsecure()` / `WiFiSSLClient` sem validar o certificado. **Em produção de
> verdade**, troque por validação com o certificado da CA.

## Sem rede, sem perder leituras (09/11/2026)

Os sketches `esp32_http` e `esp32_mqtt` medem numa tarefa própria (a medição
nunca espera a rede) e guardam cada leitura numa fila em memória (300
leituras, ~50 min a cada 10 s). Sem rede, as leituras esperam; quando a rede
volta, saem da mais antiga para a mais nova com o **instante da medição**
(`timestamp`) e um **identificador** (`id`), e a plataforma grava com o
horário certo e ignora um `id` repetido. O relógio vem do NTP ou, se ele
estiver bloqueado, do cabeçalho `Date` das respostas do servidor (só no HTTP;
no MQTT sem NTP, a leitura atrasada fica com a hora de chegada).

No MQTT, como o PubSubClient só publica com QoS 0, a placa assina o próprio
tópico e só tira a leitura da fila quando o broker a devolve (eco); a fila
sai a no máximo ~50 mensagens/min para não passar do limite da plataforma.

Teste com duas ESP32-S3 e 2 min sem servidor: nenhuma leitura perdida,
repetida ou com horário errado (antes: ~6 a 8 perdidas por minuto sem rede).

## Formato dos dados

**HTTP** — `POST {BASE_URL}/api/enviar-payload`
```
Header: X-Api-Key: <api_key>
Body:   {"device_id": 1, "payload": {"temperatura": 25.3, "umidade": 60}}
```
Limite de 2 KB por envio. Resposta `201` = salvo com sucesso.

Campos opcionais (leituras guardadas sem rede): `"timestamp"` (instante da
medição em segundos Unix, até 7 dias atrás) e `"id"` (até 100 caracteres:
letras, números, `_ . : -`). Reenviar o mesmo `id` responde `200` sem gravar
de novo. No MQTT, o equivalente é o envelope
`{"timestamp": ..., "id": "...", "payload": {...}}`.

**MQTT** — publique no tópico:
```
mqtt/projects/{PROJECT_ID}/devices/{DEVICE_ID}
```
com um objeto JSON no corpo, ex.: `{"temperatura": 25.3, "umidade": 60}`.

As chaves do JSON (ex.: `temperatura`, `umidade`) são os nomes que você vai
mapear nos gráficos da plataforma. Troque as leituras simuladas dos sketches
pelos seus sensores reais (`analogRead`, DHT, etc.).

## Teste rápido sem placa (opcional)

Para confirmar que o servidor aceita dados antes de gravar a placa, dá para
simular um dispositivo pelo terminal:

```bash
curl -X POST "http://IP_DO_SERVIDOR:8080/api/enviar-payload" \
  -H "X-Api-Key: SUA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"device_id": 1, "payload": {"temperatura": 25.3, "umidade": 60}}'
```

Resposta `201` com o ID do payload = tudo certo. Se o servidor usa HTTPS com
certificado auto-assinado (`https://IP:8443`), acrescente `-k` ao `curl`.

## Códigos de resposta HTTP no Serial Monitor

| Código | Significado |
|--------|-------------|
| `201` | Salvo. |
| `400` | JSON inválido, ou faltou `device_id`/`payload`. |
| `401` | `X-Api-Key` ausente ou errada. |
| `403` | A chave pertence a outro dispositivo (confira `DEVICE_ID`). |
| `413` | Payload maior que 2 KB. |
| `200` | Leitura com o mesmo `id` já gravada antes (reenvio): tudo certo. |
| `429` | Muitos envios por minuto (rate limit): tente de novo depois. |
| `503` | Servidor sem conseguir gravar no momento (banco fora, disco cheio): tente de novo depois. |
| negativo (`-1`…) | Não chegou ao servidor: endereço/porta errados, rede bloqueando, ou falha de TLS. |

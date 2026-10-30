# Firmware de teste — IFSCentral

Sketches prontos para colocar em **ESP32**, **ESP8266** ou **Arduino (com Wi-Fi)**
e gerar dados de teste na plataforma IFSCentral.

## O que tem aqui

| Pasta | Placa | Protocolo | Serve para |
|-------|-------|-----------|------------|
| `esp32_http/` | ESP32 | HTTP (API REST) | IFSCentral **completo e Lite** |
| `esp32_mqtt/` | ESP32 | MQTT | **Só** o IFSCentral completo (o Lite não tem MQTT) |
| `esp8266_http/` | ESP8266 / NodeMCU / Wemos D1 | HTTP | completo e Lite |
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
- **`PROJECT_ID`** — o ID do projeto (aparece na URL / na página do projeto). Só é
  usado no sketch MQTT.
- **`API_KEY`** — a chave de API do dispositivo (tem 32+ caracteres). Vai no
  header `X-Api-Key`. Usada nos sketches HTTP.
- **Credenciais MQTT** — usuário no formato `device_<DEVICE_ID>` e a senha gerada
  ao cadastrar o dispositivo. Usadas só no sketch MQTT.

Se você não tem um dispositivo cadastrado ainda, crie um em **Meus Projetos →
(projeto) → Cadastrar dispositivo**.

## Endereços e portas

**HTTP** — preencha `BASE_URL` (ESP) ou `HOST`+`PORT` (Arduino):

- Stack Docker local de testes: `http://IP_DO_SERVIDOR:8080` (ou `https://...:8443`)
- Produção: `https://ifsentral.online`

**MQTT** — preencha `MQTT_HOST` e `MQTT_PORT`:

- `1883` → MQTT em texto puro (rede local) — `USAR_TLS = false`
- `8883` → MQTT sobre TLS (fora da rede local) — `USAR_TLS = true`

> Os certificados de teste são auto-assinados, por isso os sketches usam
> `setInsecure()` / `WiFiSSLClient` sem validar o certificado. **Em produção de
> verdade**, troque por validação com o certificado da CA.

## Formato dos dados

**HTTP** — `POST {BASE_URL}/api/enviar_payload.php`
```
Header: X-Api-Key: <api_key>
Body:   {"device_id": 1, "payload": {"temperatura": 25.3, "umidade": 60}}
```
Limite de 2 KB por envio. Resposta `201` = salvo com sucesso.

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
curl -X POST "http://IP_DO_SERVIDOR:8080/api/enviar_payload.php" \
  -H "X-Api-Key: SUA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"device_id": 1, "payload": {"temperatura": 25.3, "umidade": 60}}'
```

Resposta `201` com o ID do payload = tudo certo.

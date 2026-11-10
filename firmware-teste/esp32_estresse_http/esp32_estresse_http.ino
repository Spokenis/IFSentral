/*
 * IFSCentral - Estresse HTTP (ESP32): envia e lê o mais rápido possível
 * ------------------------------------------------------------------
 * Alterna POST /api/enviar-payload (envio) e GET /api/buscar-payloads (leitura),
 * reaproveitando a conexão HTTPS (keep-alive). Cada envio leva "seq", um número
 * crescente, para conferir no banco se faltou ou sobrou alguma leitura.
 * ATENÇÃO: passa do limite padrão de 60 mensagens/min por dispositivo; para
 * medir a capacidade, defina um limite individual em device_rate_limits (e
 * remova depois). A cada 5 s imprime uma linha STAT com contadores e latências.
 */
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>

const char* WIFI_SSID     = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";
const char* BASE_URL      = "https://IP_DO_SERVIDOR:8443";
const int   DEVICE_ID     = 1;
const char* API_KEY       = "COLE_AQUI_A_API_KEY_DO_DISPOSITIVO";

WiFiClientSecure cliente;
HTTPClient http;

uint32_t seq = 0;
// contadores da janela atual (zerados a cada STAT) e totais
uint32_t envOk = 0, envErr = 0, env429 = 0, recOk = 0, recErr = 0;
uint32_t totEnvOk = 0, totEnvErr = 0, totEnv429 = 0, totRecOk = 0, totRecErr = 0;
uint32_t somaEnv = 0, maxEnv = 0, somaRec = 0, maxRec = 0;
uint32_t ultimoStat = 0, inicio = 0, ultimoSeqOk = 0;

void conectarWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);              // sem economia de energia: latência menor
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  while (WiFi.status() != WL_CONNECTED) delay(200);
  Serial.printf("WIFI ok ip=%s rssi=%d\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
}

void enviar() {
  seq++;
  char corpo[160];
  snprintf(corpo, sizeof(corpo), "{\"device_id\":%d,\"payload\":{\"seq\":%lu,\"temperatura\":%.2f,\"umidade\":%d}}",
           DEVICE_ID, (unsigned long)seq, 20.0 + (esp_random() % 1500) / 100.0, (int)(40 + esp_random() % 50));
  uint32_t t0 = millis();
  http.begin(cliente, String(BASE_URL) + "/api/enviar-payload");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", API_KEY);
  int st = http.POST((uint8_t*)corpo, strlen(corpo));
  http.getString();                  // consome a resposta para reaproveitar a conexão
  http.end();
  uint32_t dt = millis() - t0;
  if (st == 201) { envOk++; totEnvOk++; somaEnv += dt; if (dt > maxEnv) maxEnv = dt; ultimoSeqOk = seq; }
  else if (st == 429) { env429++; totEnv429++; }
  else { envErr++; totEnvErr++; if (totEnvErr <= 5) Serial.printf("ERRO envio seq=%lu status=%d (%s)\n", (unsigned long)seq, st, http.errorToString(st).c_str()); }
}

void ler() {
  uint32_t t0 = millis();
  http.begin(cliente, String(BASE_URL) + "/api/buscar-payloads?device_id=" + String(DEVICE_ID) + "&limit=10");
  http.addHeader("X-Api-Key", API_KEY);
  int st = http.GET();
  String r = http.getString();
  http.end();
  uint32_t dt = millis() - t0;
  if (st == 200 && r.length() > 2) { recOk++; totRecOk++; somaRec += dt; if (dt > maxRec) maxRec = dt; }
  else { recErr++; totRecErr++; if (totRecErr <= 5) Serial.printf("ERRO leitura status=%d\n", st); }
}

void setup() {
  Serial.begin(115200);
  delay(300);
  conectarWiFi();
  cliente.setInsecure();             // certificado autoassinado do servidor de teste
  http.setReuse(true);               // keep-alive: sem novo aperto de mão TLS a cada requisição
  http.setTimeout(10000);
  Serial.println("INICIO estresse HTTP");
  inicio = ultimoStat = millis();
}

void loop() {
  conectarWiFi();
  enviar();
  ler();
  if (millis() - ultimoStat >= 5000) {
    float seg = (millis() - ultimoStat) / 1000.0;
    Serial.printf("STAT t=%lu env_ok=%lu env_429=%lu env_err=%lu env_por_s=%.1f env_ms_med=%lu env_ms_max=%lu "
                  "rec_ok=%lu rec_err=%lu rec_por_s=%.1f rec_ms_med=%lu rec_ms_max=%lu "
                  "TOT env_ok=%lu env_429=%lu env_err=%lu rec_ok=%lu rec_err=%lu ultimo_seq_ok=%lu heap=%lu rssi=%d\n",
                  (unsigned long)((millis() - inicio) / 1000), (unsigned long)envOk, (unsigned long)env429, (unsigned long)envErr,
                  envOk / seg, (unsigned long)(envOk ? somaEnv / envOk : 0), (unsigned long)maxEnv,
                  (unsigned long)recOk, (unsigned long)recErr, recOk / seg, (unsigned long)(recOk ? somaRec / recOk : 0), (unsigned long)maxRec,
                  (unsigned long)totEnvOk, (unsigned long)totEnv429, (unsigned long)totEnvErr, (unsigned long)totRecOk, (unsigned long)totRecErr,
                  (unsigned long)ultimoSeqOk, (unsigned long)ESP.getFreeHeap(), WiFi.RSSI());
    envOk = envErr = env429 = recOk = recErr = 0; somaEnv = maxEnv = somaRec = maxRec = 0;
    ultimoStat = millis();
  }
}

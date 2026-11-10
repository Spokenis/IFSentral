/*
 * IFSCentral - Dispositivo de teste via HTTP (ESP32)
 * -------------------------------------------------
 * Mede a cada INTERVALO_MS e envia para a plataforma pela API REST.
 * Funciona tanto na edicao completa quanto no IFSCentral Lite.
 *
 * Nao perde leituras sem rede: cada medicao entra numa fila em memoria
 * (FILA_MAX leituras) e sai so depois que o servidor confirmar. Ao voltar a
 * rede, a fila e enviada da mais antiga para a mais nova, cada leitura com o
 * instante em que foi MEDIDA ("timestamp") e um identificador ("id"): se a
 * confirmacao se perder e a leitura for reenviada, a plataforma nao grava de novo.
 *
 * Endpoint: POST  {BASE_URL}/api/enviar-payload
 * Header:   X-Api-Key: <api_key do dispositivo>
 * Corpo:    {"device_id": <id>, "timestamp": <s Unix>, "id": "<id da leitura>",
 *            "payload": { ...suas leituras... }}
 *
 * Bibliotecas: ArduinoJson (Benoit Blanchon) >= 6.x
 * Placa: "ESP32 Dev Module" (ou equivalente, ex.: ESP32S3 Dev Module).
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include <time.h>
#include <sys/time.h>

// ====================== CONFIGURACAO ======================
// --- Wi-Fi ---
const char* WIFI_SSID     = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";
// Rede com login individual (WPA2-Enterprise / PEAP, ex.: rede de alunos do
// câmpus): preencha o usuário; WIFI_PASSWORD vira a senha desse usuário.
// Deixe "" para rede comum (WPA2 com senha única).
const char* WIFI_EAP_USUARIO = "";

// --- Plataforma IFSCentral ---
// Exemplos:
//   "http://192.168.0.50:8080"   (stack Docker local, porta 8080)
//   "https://10.50.0.30:8443"    (servidor HTTPS na porta 8443 — a mesma do navegador)
//   "https://ifsentral.online"   (producao)
const char* BASE_URL = "http://192.168.0.50:8080";

// Pegue estes valores na pagina do dispositivo dentro da plataforma:
const int   DEVICE_ID = 1;                 // ID numerico do dispositivo
const char* API_KEY   = "COLE_AQUI_A_API_KEY_DO_DISPOSITIVO"; // >= 32 caracteres

const unsigned long INTERVALO_MS = 10000;  // uma medicao a cada 10 s
const int FILA_MAX = 300;                  // leituras guardadas sem rede (~50 min a cada 10 s)
// =========================================================

struct Leitura {
  uint32_t medidaEm;   // millis() da medicao (vira timestamp no envio)
  uint32_t seq;        // numero da leitura desde que a placa ligou
  char json[120];      // o objeto "payload"
};

Leitura fila[FILA_MAX];
int filaInicio = 0, filaQtd = 0;
// A medicao roda numa tarefa propria (outro nucleo), num relogio fixo, e nunca
// espera a rede: no teste de 09/11/2026 uma conexao HTTPS presa durante uma
// queda de rede travou o loop() e a placa parou de MEDIR por 2 min. O semaforo
// protege a fila, usada pelas duas tarefas.
SemaphoreHandle_t travaFila;
uint32_t descartadasFilaCheia = 0;

Preferences prefs;
char idBase[32];                 // "<MAC>-<numero da inicializacao>"
uint32_t seq = 0;
unsigned long ultimaMedida = 0, proximaTentativa = 0, ultimaTentativaWiFi = 0;

WiFiClientSecure clienteTls;
WiFiClient clienteTexto;

bool relogioCerto() { return time(nullptr) > 1700000000; }

// Wi-Fi sem travar: tenta (re)conectar no maximo a cada 10 s e volta para medir
void manterWiFi() {
  if (WiFi.status() == WL_CONNECTED || millis() - ultimaTentativaWiFi < 10000) return;
  ultimaTentativaWiFi = millis();
  Serial.println("Wi-Fi fora: tentando conectar...");
  WiFi.disconnect(true);
  WiFi.mode(WIFI_STA);
  if (strlen(WIFI_EAP_USUARIO) > 0) {
    WiFi.begin(WIFI_SSID, WPA2_AUTH_PEAP, WIFI_EAP_USUARIO, WIFI_EAP_USUARIO, WIFI_PASSWORD);
  } else {
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  }
}

// Sem NTP (comum em rede de instituicao), acerta o relogio pelo cabecalho
// "Date" da resposta do proprio servidor: "Sun, 09 Nov 2026 03:00:19 GMT"
void acertarRelogioPeloServidor(HTTPClient& http) {
  if (relogioCerto()) return;
  String d = http.header("Date");
  struct tm t = {};
  if (d.length() && strptime(d.c_str(), "%a, %d %b %Y %H:%M:%S", &t)) {
    setenv("TZ", "UTC0", 1); tzset();
    struct timeval tv = { mktime(&t), 0 };
    settimeofday(&tv, nullptr);
    Serial.printf("Relogio acertado pelo servidor: %s\n", d.c_str());
  }
}

void medir() {
  xSemaphoreTake(travaFila, portMAX_DELAY);
  // ----- Troque as leituras simuladas pelos seus sensores -----
  StaticJsonDocument<128> p;
  p["temperatura"] = 20.0 + (random(0, 1500) / 100.0);
  p["umidade"]     = random(40, 90);
  p["uptime_s"]    = millis() / 1000;
  p["seq"]         = seq + 1;

  if (filaQtd == FILA_MAX) {           // fila cheia: perde a mais antiga
    filaInicio = (filaInicio + 1) % FILA_MAX;
    filaQtd--;
    descartadasFilaCheia++;
  }
  Leitura& l = fila[(filaInicio + filaQtd) % FILA_MAX];
  l.medidaEm = millis();
  l.seq = ++seq;
  serializeJson(p, l.json, sizeof(l.json));
  filaQtd++;
  xSemaphoreGive(travaFila);
}

void tarefaMedicao(void*) {
  TickType_t proxima = xTaskGetTickCount();
  for (;;) {
    medir();
    vTaskDelayUntil(&proxima, pdMS_TO_TICKS(INTERVALO_MS));
  }
}

// Envia a leitura mais antiga. Devolve true se ela saiu da fila.
bool enviarMaisAntiga() {
  // Copia a leitura mais antiga (a tarefa de medicao pode mexer na fila)
  xSemaphoreTake(travaFila, portMAX_DELAY);
  Leitura l = fila[filaInicio];
  xSemaphoreGive(travaFila);
  char corpo[320];
  char id[48];
  snprintf(id, sizeof(id), "%s-%lu", idBase, (unsigned long)l.seq);
  if (relogioCerto()) {
    uint32_t ts = time(nullptr) - (millis() - l.medidaEm) / 1000;
    snprintf(corpo, sizeof(corpo), "{\"device_id\":%d,\"timestamp\":%lu,\"id\":\"%s\",\"payload\":%s}",
             DEVICE_ID, (unsigned long)ts, id, l.json);
  } else {                             // sem relogio ainda: o servidor usa a hora de chegada
    snprintf(corpo, sizeof(corpo), "{\"device_id\":%d,\"id\":\"%s\",\"payload\":%s}", DEVICE_ID, id, l.json);
  }

  HTTPClient http;
  String url = String(BASE_URL) + "/api/enviar-payload";
  bool ok = url.startsWith("https") ? http.begin(clienteTls, url) : http.begin(clienteTexto, url);
  if (!ok) return false;
  const char* cabecalhos[] = {"Date"};
  http.collectHeaders(cabecalhos, 1);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", API_KEY);
  http.setConnectTimeout(5000);   // limites: uma conexao presa nao segura o envio para sempre
  http.setTimeout(8000);
  int status = http.POST((uint8_t*)corpo, strlen(corpo));
  if (status > 0) acertarRelogioPeloServidor(http);
  String resposta = http.getString();
  http.end();

  if (status == 201 || status == 200) {          // gravada (200 = ja tinha sido gravada antes)
    Serial.printf("Enviada leitura %lu (fila: %d) -> HTTP %d\n", (unsigned long)l.seq, filaQtd - 1, status);
    return true;
  }
  if (status == 400 || status == 401 || status == 403 || status == 413) {
    // Erro da propria leitura ou da configuracao: repetir nao adianta
    Serial.printf("Leitura %lu recusada (HTTP %d): %s\n", (unsigned long)l.seq, status, resposta.c_str());
    return true;
  }
  // 429, 503, 5xx ou sem conexao: fica na fila para depois
  Serial.printf("Envio adiado (HTTP %d %s); %d na fila\n", status, status < 0 ? http.errorToString(status).c_str() : "", filaQtd);
  return false;
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(esp_random());
  clienteTls.setInsecure();              // aceita certificado self-signed (teste)
  clienteTls.setHandshakeTimeout(8);     // segundos para o aperto de mao TLS

  // Numero desta inicializacao, guardado na flash: o id de cada leitura
  // ("<MAC>-<boot>-<seq>") nunca se repete, nem depois de reiniciar
  prefs.begin("ifscentral", false);
  uint32_t boot = prefs.getUInt("boot", 0) + 1;
  prefs.putUInt("boot", boot);
  snprintf(idBase, sizeof(idBase), "%012llX-%lu", ESP.getEfuseMac(), (unsigned long)boot);

  configTime(0, 0, "pool.ntp.org", "a.st1.ntp.br");  // se o NTP for bloqueado, vale o Date do servidor
  manterWiFi();
  travaFila = xSemaphoreCreateMutex();
  xTaskCreatePinnedToCore(tarefaMedicao, "medicao", 4096, nullptr, 1, nullptr, 0);
  Serial.printf("Dispositivo %d iniciado (id %s)\n", DEVICE_ID, idBase);
}

void loop() {
  manterWiFi();

  // Esvazia a fila (ate 10 por volta) enquanto o servidor aceitar
  if (WiFi.status() == WL_CONNECTED && filaQtd > 0 && millis() >= proximaTentativa) {
    for (int i = 0; i < 10 && filaQtd > 0; i++) {
      uint32_t enviada = fila[filaInicio].seq;
      if (!enviarMaisAntiga()) {
        proximaTentativa = millis() + 5000;     // servidor indisponivel: tenta de novo em 5 s
        break;
      }
      xSemaphoreTake(travaFila, portMAX_DELAY);
      // so remove se ainda for a mesma (a fila cheia pode ter descartado a mais antiga)
      if (filaQtd > 0 && fila[filaInicio].seq == enviada) {
        filaInicio = (filaInicio + 1) % FILA_MAX;
        filaQtd--;
      }
      xSemaphoreGive(travaFila);
    }
  }
  delay(20);
}

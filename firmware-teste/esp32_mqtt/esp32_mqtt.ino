/*
 * IFSCentral - Dispositivo de teste via MQTT (ESP32)
 * --------------------------------------------------
 * Mede a cada INTERVALO_MS e publica no broker MQTT da plataforma.
 * SO funciona na edicao COMPLETA do IFSCentral (o Lite nao tem MQTT).
 *
 * Nao perde leituras sem rede: cada medicao entra numa fila em memoria
 * (FILA_MAX leituras); sem conexao com o broker, as leituras esperam e sao
 * publicadas, da mais antiga para a mais nova, quando a conexao volta. Cada
 * uma vai num envelope com o instante da MEDICAO e um identificador:
 *   {"timestamp": <s Unix>, "id": "<id>", "payload": {...suas leituras...}}
 * (a plataforma grava com o horario certo e ignora um id repetido).
 *
 * Confirmacao: o PubSubClient so publica com QoS 0, e uma mensagem escrita no
 * instante em que a rede cai se perde. Por isso a placa assina o proprio
 * topico e so tira a leitura da fila quando o broker devolve a mensagem (o
 * "eco"); sem eco em 5 s, publica de novo. Teste de 09/11/2026: sem isso, 2
 * leituras se perdiam numa queda de rede de 2 min; com isso, nenhuma.
 *
 * Topico:  mqtt/projects/{PROJECT_ID}/devices/{DEVICE_ID}
 * Login no broker: usuario "mqdev_..." + senha, mostrados na pagina do
 * dispositivo (secao MQTT) e na tela que aparece logo apos cadastra-lo.
 *
 * Portas do broker:
 *   1883 -> MQTT em texto puro (rede local)
 *   8883 -> MQTT sobre TLS (recomendado fora da rede local)
 *
 * Bibliotecas: PubSubClient (Nick O'Leary), ArduinoJson (Benoit Blanchon) >= 6.x
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include <time.h>

// ====================== CONFIGURACAO ======================
// --- Wi-Fi ---
const char* WIFI_SSID     = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";
// Rede com login individual (WPA2-Enterprise / PEAP, ex.: rede de alunos do
// câmpus): preencha o usuário; WIFI_PASSWORD vira a senha desse usuário.
// Deixe "" para rede comum (WPA2 com senha única).
const char* WIFI_EAP_USUARIO = "";

// --- Broker MQTT ---
const char* MQTT_HOST = "192.168.0.50"; // IP/host do servidor IFSCentral
const int   MQTT_PORT = 1883;           // 1883 (texto) ou 8883 (TLS)
const bool  USAR_TLS  = false;          // true se usar a porta 8883

// --- Identificacao do dispositivo ---
// Os dois numeros estao no topico MQTT mostrado na aba "Dispositivos" do
// projeto e na pagina do dispositivo: mqtt/projects/PROJECT_ID/devices/DEVICE_ID
const int   PROJECT_ID = 1;
const int   DEVICE_ID  = 1;
// Credencial do broker: copie da pagina do dispositivo (NAO e "device_<ID>";
// o usuario tem o formato "mqdev_" + 16 caracteres)
const char* MQTT_USER  = "mqdev_COLE_AQUI_O_USUARIO";
const char* MQTT_PASS  = "COLE_AQUI_A_SENHA_MQTT_DO_DISPOSITIVO";

const unsigned long INTERVALO_MS = 10000;  // uma medicao a cada 10 s
const int FILA_MAX = 300;                  // leituras guardadas sem rede (~50 min a cada 10 s)
// =========================================================

struct Leitura { uint32_t medidaEm; uint32_t seq; char json[120]; };
Leitura fila[FILA_MAX];
int filaInicio = 0, filaQtd = 0;
// A medicao roda numa tarefa propria (outro nucleo), num relogio fixo, e nunca
// espera a rede: no teste de 09/11/2026 uma conexao HTTPS presa durante uma
// queda de rede travou o loop() e a placa parou de MEDIR por 2 min. O semaforo
// protege a fila, usada pelas duas tarefas.
SemaphoreHandle_t travaFila;

WiFiClient       wifiPlain;
WiFiClientSecure wifiTls;
PubSubClient     mqtt;
Preferences      prefs;

char topico[80];
char idBase[32];
bool aguardandoEco = false;        // a leitura mais antiga foi publicada e espera o eco
char idPendente[48];
unsigned long publicadaEm = 0;
uint32_t seq = 0;
unsigned long ultimaMedida = 0, ultimaTentativaWiFi = 0, ultimaTentativaMqtt = 0;

bool relogioCerto() { return time(nullptr) > 1700000000; }

void manterWiFi() {
  if (WiFi.status() == WL_CONNECTED || millis() - ultimaTentativaWiFi < 10000) return;
  ultimaTentativaWiFi = millis();
  Serial.println("Wi-Fi fora: tentando conectar...");
  WiFi.disconnect(true);
  WiFi.mode(WIFI_STA);
  if (strlen(WIFI_EAP_USUARIO) > 0) {
    // WPA2-Enterprise (PEAP/MSCHAPv2), sem validar o certificado do servidor RADIUS
    WiFi.begin(WIFI_SSID, WPA2_AUTH_PEAP, WIFI_EAP_USUARIO, WIFI_EAP_USUARIO, WIFI_PASSWORD);
  } else {
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  }
}

// Broker sem travar: tenta no maximo a cada 5 s e volta para medir
void manterMqtt() {
  if (mqtt.connected() || WiFi.status() != WL_CONNECTED || millis() - ultimaTentativaMqtt < 5000) return;
  ultimaTentativaMqtt = millis();
  String clientId = "esp32-" + String(DEVICE_ID) + "-" + String((uint32_t)esp_random(), HEX);
  Serial.print("Conectando ao broker MQTT...");
  if (mqtt.connect(clientId.c_str(), MQTT_USER, MQTT_PASS)) {
    mqtt.subscribe(topico);          // para receber o eco das proprias publicacoes
    aguardandoEco = false;           // o que estava pendente sera publicado de novo
    Serial.println(" OK");
  } else {
    int rc = mqtt.state();
    Serial.printf(" falhou (rc=%d) ", rc);
    if (rc == -2) Serial.print("sem conexao TCP com o broker: porta bloqueada pela rede/firewall ou host errado.");
    else if (rc == 4 || rc == 5) Serial.print("usuario/senha MQTT recusados: confira MQTT_USER e MQTT_PASS na pagina do dispositivo.");
    Serial.println(" Nova tentativa em 5 s.");
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

// Eco de uma publicacao: se for a leitura pendente, ela saiu da fila
void recebido(char* t, byte* corpo, unsigned int n) {
  if (!aguardandoEco || filaQtd == 0) return;
  char alvo[64];
  snprintf(alvo, sizeof(alvo), "\"id\":\"%s\"", idPendente);
  char buf[320];
  n = n < sizeof(buf) - 1 ? n : sizeof(buf) - 1;
  memcpy(buf, corpo, n); buf[n] = 0;
  if (strstr(buf, alvo)) {
    xSemaphoreTake(travaFila, portMAX_DELAY);
    char idMaisAntiga[48];
    snprintf(idMaisAntiga, sizeof(idMaisAntiga), "%s-%lu", idBase, (unsigned long)fila[filaInicio].seq);
    if (filaQtd > 0 && strcmp(idMaisAntiga, idPendente) == 0) {   // ainda e a mesma leitura
      filaInicio = (filaInicio + 1) % FILA_MAX;
      filaQtd--;
    }
    xSemaphoreGive(travaFila);
    Serial.printf("Confirmada leitura %s (fila: %d)\n", idPendente, filaQtd);
    aguardandoEco = false;
  }
}

bool publicarMaisAntiga() {
  xSemaphoreTake(travaFila, portMAX_DELAY);
  Leitura l = fila[filaInicio];
  xSemaphoreGive(travaFila);
  char msg[300];
  if (relogioCerto()) {
    uint32_t ts = time(nullptr) - (millis() - l.medidaEm) / 1000;
    snprintf(msg, sizeof(msg), "{\"timestamp\":%lu,\"id\":\"%s-%lu\",\"payload\":%s}",
             (unsigned long)ts, idBase, (unsigned long)l.seq, l.json);
  } else {                             // sem relogio: o servidor usa a hora de chegada
    snprintf(msg, sizeof(msg), "{\"id\":\"%s-%lu\",\"payload\":%s}", idBase, (unsigned long)l.seq, l.json);
  }
  if (!mqtt.publish(topico, msg)) return false;
  snprintf(idPendente, sizeof(idPendente), "%s-%lu", idBase, (unsigned long)l.seq);
  aguardandoEco = true;
  publicadaEm = millis();
  return true;
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(esp_random());
  snprintf(topico, sizeof(topico), "mqtt/projects/%d/devices/%d", PROJECT_ID, DEVICE_ID);

  prefs.begin("ifscentral", false);
  uint32_t boot = prefs.getUInt("boot", 0) + 1;
  prefs.putUInt("boot", boot);
  snprintf(idBase, sizeof(idBase), "%012llX-%lu", ESP.getEfuseMac(), (unsigned long)boot);

  if (USAR_TLS) {
    wifiTls.setInsecure();             // aceita cert self-signed (teste)
    mqtt.setClient(wifiTls);
  } else {
    mqtt.setClient(wifiPlain);
  }
  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setBufferSize(512);             // o envelope passa dos 256 bytes padrao
  mqtt.setCallback(recebido);
  configTime(0, 0, "pool.ntp.org", "a.st1.ntp.br");
  manterWiFi();
  travaFila = xSemaphoreCreateMutex();
  xTaskCreatePinnedToCore(tarefaMedicao, "medicao", 4096, nullptr, 1, nullptr, 0);
  Serial.printf("Dispositivo %d iniciado (id %s)\n", DEVICE_ID, idBase);
}

void loop() {
  manterWiFi();
  manterMqtt();
  mqtt.loop();

  // Esvazia a fila em ritmo controlado: no maximo uma publicacao a cada
  // 1,2 s (~50/min). Pelo MQTT o dispositivo nao recebe aviso do limite da
  // plataforma (60 mensagens/min por dispositivo): o excedente seria
  // descartado em silencio, entao a fila acumulada nao pode sair de uma vez.
  static unsigned long ultimaPublicacao = 0;
  if (aguardandoEco && millis() - publicadaEm > 5000) {
    aguardandoEco = false;             // sem eco: publica a mesma leitura de novo
    Serial.printf("Sem confirmacao de %s; publicando de novo\n", idPendente);
  }
  if (!aguardandoEco && filaQtd > 0 && mqtt.connected() && millis() - ultimaPublicacao >= 1200) {
    publicarMaisAntiga();
    ultimaPublicacao = millis();
  }
  delay(20);
}

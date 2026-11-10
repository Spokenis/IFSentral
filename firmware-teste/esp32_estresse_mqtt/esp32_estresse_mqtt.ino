/*
 * IFSCentral - Estresse MQTT (ESP32): publica o mais rápido possível e recebe de volta
 * --------------------------------------------------------------------------------
 * Publica no próprio tópico (QoS 0, o que o PubSubClient suporta) e assina esse
 * mesmo tópico: cada mensagem volta pelo broker, e a placa mede o tempo de ida e
 * volta. Cada mensagem leva "seq" para conferir no banco o que foi gravado.
 * ATENÇÃO: passa do limite padrão de 60 mensagens/min por dispositivo; para
 * medir a capacidade, defina um limite individual em device_rate_limits (e
 * remova depois). A cada 5 s imprime uma linha STAT.
 */
#include <WiFi.h>
#include <PubSubClient.h>

const char* WIFI_SSID     = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";
const char* MQTT_HOST     = "IP_DO_SERVIDOR";
const int   MQTT_PORT     = 1883;
const int   PROJECT_ID    = 1;
const int   DEVICE_ID     = 1;
const char* MQTT_USER     = "mqdev_COLE_AQUI_O_USUARIO";
const char* MQTT_PASS     = "COLE_AQUI_A_SENHA_MQTT";
// Pausa mínima entre publicações (ms). 0 = o mais rápido possível.
const uint32_t PAUSA_MS   = 0;

WiFiClient wifi;
PubSubClient mqtt(wifi);
char topico[80];

uint32_t seq = 0;
uint32_t pub = 0, pubErr = 0, rec = 0, totPub = 0, totPubErr = 0, totRec = 0, reconexoes = 0;
uint32_t somaRtt = 0, maxRtt = 0, ultimoStat = 0, inicio = 0;

void recebido(char* t, byte* msg, unsigned int len) {
  // payload: {"seq":N,"t":MILLIS,...}: o "t" é o millis() desta placa no envio
  char buf[200];
  len = len < sizeof(buf) - 1 ? len : sizeof(buf) - 1;
  memcpy(buf, msg, len); buf[len] = 0;
  char* p = strstr(buf, "\"t\":");
  if (p) {
    uint32_t enviado = strtoul(p + 4, nullptr, 10);
    uint32_t rtt = millis() - enviado;
    somaRtt += rtt; if (rtt > maxRtt) maxRtt = rtt;
  }
  rec++; totRec++;
}

void conectar() {
  if (WiFi.status() != WL_CONNECTED) {
    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    while (WiFi.status() != WL_CONNECTED) delay(200);
    Serial.printf("WIFI ok ip=%s rssi=%d\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
  }
  while (!mqtt.connected()) {
    String id = "estresse-" + String(DEVICE_ID) + "-" + String((uint32_t)esp_random(), HEX);
    if (mqtt.connect(id.c_str(), MQTT_USER, MQTT_PASS)) {
      mqtt.subscribe(topico, 0);
      Serial.println("MQTT conectado e assinado");
    } else {
      Serial.printf("MQTT falhou rc=%d\n", mqtt.state());
      delay(1000);
    }
    reconexoes++;
  }
}

void setup() {
  Serial.begin(115200);
  delay(300);
  snprintf(topico, sizeof(topico), "mqtt/projects/%d/devices/%d", PROJECT_ID, DEVICE_ID);
  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setCallback(recebido);
  mqtt.setBufferSize(512);
  mqtt.setKeepAlive(30);
  conectar();
  Serial.println("INICIO estresse MQTT");
  inicio = ultimoStat = millis();
}

void loop() {
  conectar();
  seq++;
  char msg[160];
  snprintf(msg, sizeof(msg), "{\"seq\":%lu,\"t\":%lu,\"temperatura\":%.2f,\"umidade\":%d}",
           (unsigned long)seq, (unsigned long)millis(), 20.0 + (esp_random() % 1500) / 100.0, (int)(40 + esp_random() % 50));
  if (mqtt.publish(topico, msg)) { pub++; totPub++; } else { pubErr++; totPubErr++; }
  mqtt.loop();                       // entrega as mensagens que voltaram
  if (PAUSA_MS) delay(PAUSA_MS);
  if (millis() - ultimoStat >= 5000) {
    float seg = (millis() - ultimoStat) / 1000.0;
    Serial.printf("STAT t=%lu pub=%lu pub_err=%lu pub_por_s=%.1f rec=%lu rec_por_s=%.1f rtt_ms_med=%lu rtt_ms_max=%lu "
                  "TOT pub=%lu pub_err=%lu rec=%lu ultimo_seq=%lu reconexoes=%lu heap=%lu rssi=%d\n",
                  (unsigned long)((millis() - inicio) / 1000), (unsigned long)pub, (unsigned long)pubErr, pub / seg,
                  (unsigned long)rec, rec / seg, (unsigned long)(rec ? somaRtt / rec : 0), (unsigned long)maxRtt,
                  (unsigned long)totPub, (unsigned long)totPubErr, (unsigned long)totRec, (unsigned long)seq,
                  (unsigned long)(reconexoes - 1), (unsigned long)ESP.getFreeHeap(), WiFi.RSSI());
    pub = pubErr = rec = 0; somaRtt = maxRtt = 0;
    ultimoStat = millis();
  }
}

/*
 * IFSCentral - Teste de dispositivo via MQTT (ESP32)
 * --------------------------------------------------
 * Publica um payload JSON direto no broker MQTT da plataforma.
 * SO funciona na edicao COMPLETA do IFSCentral (o Lite nao tem MQTT).
 *
 * Topico:  mqtt/projects/{PROJECT_ID}/devices/{DEVICE_ID}
 * Payload: objeto JSON com suas leituras, ex.: {"temperatura":25.3,"umidade":60}
 * Login no broker: usuario "device_{DEVICE_ID}" + senha do dispositivo.
 *
 * Portas do broker:
 *   1883 -> MQTT em texto puro (rede local)
 *   8883 -> MQTT sobre TLS (recomendado fora da rede local)
 *
 * Bibliotecas (Gerenciador de Bibliotecas):
 *   - PubSubClient (Nick O'Leary)
 *   - ArduinoJson  (Benoit Blanchon) >= 6.x
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>

// ====================== CONFIGURACAO ======================
// --- Wi-Fi ---
const char* WIFI_SSID     = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";

// --- Broker MQTT ---
const char* MQTT_HOST = "192.168.0.50"; // IP/host do servidor IFSCentral
const int   MQTT_PORT = 1883;           // 1883 (texto) ou 8883 (TLS)
const bool  USAR_TLS  = false;          // true se usar a porta 8883

// --- Identificacao do dispositivo (pagina do dispositivo na plataforma) ---
const int   PROJECT_ID = 1;
const int   DEVICE_ID  = 1;
// Credencial do broker: usuario = "device_<DEVICE_ID>"
const char* MQTT_USER  = "device_1";
const char* MQTT_PASS  = "COLE_AQUI_A_SENHA_MQTT_DO_DISPOSITIVO";

// Intervalo entre publicacoes (ms)
const unsigned long INTERVALO_MS = 10000;
// =========================================================

WiFiClient       wifiPlain;
WiFiClientSecure wifiTls;
PubSubClient     mqtt;

char topico[80];
unsigned long ultimoEnvio = 0;

void conectarWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  Serial.print("Conectando ao Wi-Fi");
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.print("\nConectado. IP: ");
  Serial.println(WiFi.localIP());
}

void conectarMQTT() {
  while (!mqtt.connected()) {
    conectarWiFi();
    String clientId = "esp32-" + String(DEVICE_ID) + "-" + String((uint32_t)esp_random(), HEX);
    Serial.print("Conectando ao broker MQTT...");
    if (mqtt.connect(clientId.c_str(), MQTT_USER, MQTT_PASS)) {
      Serial.println(" OK");
    } else {
      Serial.print(" falhou (rc=");
      Serial.print(mqtt.state());
      Serial.println("). Tentando de novo em 3s.");
      delay(3000);
    }
  }
}

void publicarPayload() {
  StaticJsonDocument<256> doc;
  // Exemplos simulados (substitua pelos seus sensores):
  doc["temperatura"] = 20.0 + (random(0, 1500) / 100.0);
  doc["umidade"]     = random(40, 90);
  doc["uptime_s"]    = millis() / 1000;

  char buffer[256];
  size_t n = serializeJson(doc, buffer);

  Serial.print("Publicando em ");
  Serial.print(topico);
  Serial.print(" -> ");
  Serial.println(buffer);

  if (!mqtt.publish(topico, buffer, n)) {
    Serial.println("Falha ao publicar.");
  }
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(micros());

  snprintf(topico, sizeof(topico),
           "mqtt/projects/%d/devices/%d", PROJECT_ID, DEVICE_ID);

  if (USAR_TLS) {
    wifiTls.setInsecure();            // aceita cert self-signed (teste)
    mqtt.setClient(wifiTls);
  } else {
    mqtt.setClient(wifiPlain);
  }
  mqtt.setServer(MQTT_HOST, MQTT_PORT);

  conectarWiFi();
  conectarMQTT();
  publicarPayload();
  ultimoEnvio = millis();
}

void loop() {
  if (!mqtt.connected()) conectarMQTT();
  mqtt.loop();

  if (millis() - ultimoEnvio >= INTERVALO_MS) {
    publicarPayload();
    ultimoEnvio = millis();
  }
}

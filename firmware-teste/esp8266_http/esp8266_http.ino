/*
 * IFSCentral - Teste de dispositivo via HTTP (ESP8266 / NodeMCU / Wemos D1)
 * ------------------------------------------------------------------------
 * Mesma API REST do ESP32, adaptada para o core do ESP8266.
 * Funciona na edicao completa e no IFSCentral Lite.
 *
 * Endpoint: POST {BASE_URL}/api/enviar_payload.php
 * Header:   X-Api-Key: <api_key>
 * Corpo:    {"device_id": <id>, "payload": { ... }}
 *
 * Bibliotecas:
 *   - ArduinoJson (Benoit Blanchon) >= 6.x
 *   (ESP8266WiFi e ESP8266HTTPClient ja vem no core do ESP8266)
 */

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>

// ====================== CONFIGURACAO ======================
const char* WIFI_SSID     = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";

// http://IP:8080  ou  https://seu-dominio
const char* BASE_URL = "http://192.168.0.50:8080";

const int   DEVICE_ID = 1;
const char* API_KEY   = "COLE_AQUI_A_API_KEY_DO_DISPOSITIVO";

const unsigned long INTERVALO_MS = 10000;
// =========================================================

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

void enviarPayload() {
  StaticJsonDocument<256> doc;
  doc["device_id"] = DEVICE_ID;
  JsonObject payload = doc.createNestedObject("payload");
  payload["temperatura"] = 20.0 + (random(0, 1500) / 100.0);
  payload["umidade"]     = random(40, 90);
  payload["uptime_s"]    = millis() / 1000;

  String corpo;
  serializeJson(doc, corpo);

  HTTPClient http;
  String url = String(BASE_URL) + "/api/enviar_payload.php";
  bool ok;

  if (url.startsWith("https")) {
    WiFiClientSecure client;
    client.setInsecure();
    ok = http.begin(client, url);
  } else {
    WiFiClient client;
    ok = http.begin(client, url);
  }

  if (!ok) {
    Serial.println("Falha ao iniciar conexao HTTP.");
    return;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", API_KEY);

  Serial.println("Enviando: " + corpo);
  int status = http.POST(corpo);
  Serial.print("HTTP ");
  Serial.print(status);
  Serial.print(" -> ");
  Serial.println(http.getString());

  http.end();
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(micros());
  conectarWiFi();
  enviarPayload();
  ultimoEnvio = millis();
}

void loop() {
  conectarWiFi();
  if (millis() - ultimoEnvio >= INTERVALO_MS) {
    enviarPayload();
    ultimoEnvio = millis();
  }
}

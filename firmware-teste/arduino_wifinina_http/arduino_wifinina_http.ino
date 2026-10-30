/*
 * IFSCentral - Teste de dispositivo via HTTP (Arduino com WiFiNINA)
 * ----------------------------------------------------------------
 * Para placas Arduino com Wi-Fi via modulo NINA:
 *   - Arduino UNO WiFi Rev2
 *   - Arduino Nano 33 IoT
 *   - Arduino MKR WiFi 1010
 *
 * Observacao: um Arduino UNO/Nano CLASSICO (sem Wi-Fi) nao consegue
 * falar HTTP sozinho. Nesse caso use um ESP32/ESP8266, ou um shield
 * Ethernet (biblioteca Ethernet + EthernetClient, mesma logica abaixo).
 *
 * Bibliotecas (Gerenciador de Bibliotecas):
 *   - WiFiNINA (Arduino)
 *   - ArduinoJson (Benoit Blanchon) >= 6.x
 */

#include <WiFiNINA.h>
#include <ArduinoJson.h>

// ====================== CONFIGURACAO ======================
char WIFI_SSID[]     = "SUA_REDE_WIFI";
char WIFI_PASSWORD[] = "SUA_SENHA_WIFI";

// Host SEM http:// e sem porta aqui (porta vai separada abaixo).
const char* HOST = "192.168.0.50";
const int   PORT = 8080;          // 8080 para a stack Docker; 80 para HTTP padrao
const bool  USAR_TLS = false;     // true usa porta 443/HTTPS (WiFiSSLClient)

const int   DEVICE_ID = 1;
const char* API_KEY   = "COLE_AQUI_A_API_KEY_DO_DISPOSITIVO";

const unsigned long INTERVALO_MS = 10000;
// =========================================================

unsigned long ultimoEnvio = 0;

void conectarWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  Serial.print("Conectando ao Wi-Fi");
  while (WiFi.begin(WIFI_SSID, WIFI_PASSWORD) != WL_CONNECTED) {
    Serial.print(".");
    delay(2000);
  }
  Serial.print("\nConectado. IP: ");
  Serial.println(WiFi.localIP());
}

void enviarPayload() {
  // Monta o corpo JSON
  StaticJsonDocument<256> doc;
  doc["device_id"] = DEVICE_ID;
  JsonObject payload = doc.createNestedObject("payload");
  payload["temperatura"] = 20.0 + (random(0, 1500) / 100.0);
  payload["umidade"]     = random(40, 90);
  payload["uptime_s"]    = millis() / 1000;

  String corpo;
  serializeJson(doc, corpo);

  // Escolhe cliente (TLS ou texto)
  WiFiClient    clientPlain;
  WiFiSSLClient clientTls;
  Client* client = USAR_TLS ? (Client*)&clientTls : (Client*)&clientPlain;

  Serial.print("Conectando a ");
  Serial.print(HOST);
  Serial.print(":");
  Serial.println(PORT);

  if (!client->connect(HOST, PORT)) {
    Serial.println("Falha ao conectar no servidor.");
    return;
  }

  // Requisicao HTTP manual
  client->print("POST /api/enviar_payload.php HTTP/1.1\r\n");
  client->print("Host: "); client->print(HOST); client->print("\r\n");
  client->print("X-Api-Key: "); client->print(API_KEY); client->print("\r\n");
  client->print("Content-Type: application/json\r\n");
  client->print("Content-Length: "); client->print(corpo.length()); client->print("\r\n");
  client->print("Connection: close\r\n\r\n");
  client->print(corpo);

  Serial.println("Enviado: " + corpo);

  // Le a resposta
  unsigned long t0 = millis();
  while (client->connected() && millis() - t0 < 5000) {
    while (client->available()) {
      Serial.write(client->read());
      t0 = millis();
    }
  }
  Serial.println();
  client->stop();
}

void setup() {
  Serial.begin(115200);
  while (!Serial && millis() < 3000);
  randomSeed(analogRead(A0));
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

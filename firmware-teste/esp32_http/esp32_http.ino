/*
 * IFSCentral - Teste de dispositivo via HTTP (ESP32)
 * -------------------------------------------------
 * Envia um payload JSON para a plataforma usando a API REST.
 * Funciona tanto na edicao completa quanto no IFSCentral Lite.
 *
 * Endpoint: POST  {BASE_URL}/api/enviar_payload.php
 * Header:   X-Api-Key: <api_key do dispositivo>
 * Corpo:    {"device_id": <id>, "payload": { ...suas leituras... }}
 *
 * Bibliotecas (Gerenciador de Bibliotecas do Arduino IDE):
 *   - ArduinoJson (Benoit Blanchon)  >= 6.x
 *   (HTTPClient e WiFi ja vem no core do ESP32)
 *
 * Placa: "ESP32 Dev Module" (ou equivalente).
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>

// ====================== CONFIGURACAO ======================
// --- Wi-Fi ---
const char* WIFI_SSID     = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";

// --- Plataforma IFSCentral ---
// Use http://  OU  https:// conforme o seu servidor.
// Exemplos:
//   "http://192.168.0.50:8080"   (stack Docker local, porta 8080)
//   "https://ifsentral.online"   (producao)
const char* BASE_URL = "http://192.168.0.50:8080";

// Pegue estes valores na pagina do dispositivo dentro da plataforma:
const int   DEVICE_ID = 1;                 // ID numerico do dispositivo
const char* API_KEY   = "COLE_AQUI_A_API_KEY_DO_DISPOSITIVO"; // >= 32 caracteres

// Intervalo entre envios (ms)
const unsigned long INTERVALO_MS = 10000;  // 10 s
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

// Monta e envia o payload. Troque as leituras simuladas pelos seus sensores.
void enviarPayload() {
  // ----- 1) Monte o objeto "payload" com suas leituras -----
  StaticJsonDocument<256> doc;
  doc["device_id"] = DEVICE_ID;
  JsonObject payload = doc.createNestedObject("payload");

  // Exemplos simulados (substitua por analogRead / sensores reais):
  payload["temperatura"] = 20.0 + (random(0, 1500) / 100.0); // 20.00 - 35.00
  payload["umidade"]     = random(40, 90);                   // 40 - 89
  payload["uptime_s"]    = millis() / 1000;

  String corpo;
  serializeJson(doc, corpo);

  // ----- 2) Escolhe cliente HTTP ou HTTPS -----
  HTTPClient http;
  String url = String(BASE_URL) + "/api/enviar_payload.php";
  bool ok;

  if (url.startsWith("https")) {
    WiFiClientSecure client;
    client.setInsecure();            // aceita certificado self-signed (teste)
    ok = http.begin(client, url);
  } else {
    ok = http.begin(url);
  }

  if (!ok) {
    Serial.println("Falha ao iniciar conexao HTTP.");
    return;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", API_KEY);

  Serial.println("Enviando: " + corpo);
  int status = http.POST(corpo);
  String resposta = http.getString();

  Serial.print("HTTP ");
  Serial.print(status);
  Serial.print(" -> ");
  Serial.println(resposta);

  http.end();
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(micros());
  conectarWiFi();
  enviarPayload();            // primeiro envio imediato
  ultimoEnvio = millis();
}

void loop() {
  conectarWiFi();
  if (millis() - ultimoEnvio >= INTERVALO_MS) {
    enviarPayload();
    ultimoEnvio = millis();
  }
}

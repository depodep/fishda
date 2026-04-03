// ============================================================
//  Smart Fish Drying System — ESP8266 + DHT11
//  FIXED VERSION — all 11 issues corrected
// ============================================================

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>    
#include <DHT.h>
#include <ArduinoJson.h>         

// ── PIN DEFINITIONS ───────────────────────────────────────
#define DHTPIN      D2   // DHT11 data pin
#define DHTTYPE     DHT11

#define HEATER_PIN  D5   
#define EXHAUST_PIN D6  
#define FAN_PIN     D7    

// ── WIFI CREDENTIALS ─────────────────────────────────────
const char* ssid     = "Redmi Note 13";
const char* password = "aaaaaaaa";

// ── DEVICE IDENTITY / ACCESS ─────────────────────────────
const String espAccessCode = "APS-ESP-2026";
const String modelUnit = "Fishda";
const String modelUnitCode  = "FD2026";
const String deviceUniqueCode = "ESP8266-UNIT-001";

// ── SERVER CONFIGURATION ─────────────────────────────────
const char* serverIP = "10.59.216.71";
const String baseURL = String("http://") + serverIP + "/capstone";
String logReadingURL = baseURL + "/session_api.php";
String sessionStatusURL = baseURL + "/session_api.php?action=poll_session_status";

// ── TIMING ───────────────────────────────────────────────
unsigned long lastSendTime = 0;
const unsigned long SEND_INTERVAL = 5000;  

 int  currentSessionId = 0;
bool sessionRunning   = false;

 DHT dht(DHTPIN, DHTTYPE);
WiFiClient wifiClient;

String urlEncode(const String& value) {
  String encoded = "";
  char c;
  char hex[4];
  for (unsigned int i = 0; i < value.length(); i++) {
    c = value.charAt(i);
    if ((c >= 'a' && c <= 'z') ||
        (c >= 'A' && c <= 'Z') ||
        (c >= '0' && c <= '9') ||
        c == '-' || c == '_' || c == '.' || c == '~') {
      encoded += c;
    } else {
      snprintf(hex, sizeof(hex), "%%%02X", (unsigned char)c);
      encoded += hex;
    }
  }
  return encoded;
}

// ── SETUP ─────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(100);

  // FIX 8: Initialize relay pins as OUTPUT, default OFF
  pinMode(HEATER_PIN,  OUTPUT); digitalWrite(HEATER_PIN,  LOW);
  pinMode(EXHAUST_PIN, OUTPUT); digitalWrite(EXHAUST_PIN, LOW);
  pinMode(FAN_PIN,     OUTPUT); digitalWrite(FAN_PIN,     LOW);

  dht.begin();

  // Connect to WiFi
  Serial.println("\nConnecting to WiFi...");
  WiFi.begin(ssid, password);
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 30) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n✅ WiFi Connected!");
    Serial.print("ESP8266 IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("\n❌ WiFi failed! Check credentials.");
  }
}

// ── MAIN LOOP ─────────────────────────────────────────────
// FIX 11: loop() now actually sends data instead of just serving a web page
void loop() {
  // Reconnect WiFi if dropped
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi lost. Reconnecting...");
    WiFi.begin(ssid, password);
    delay(3000);
    return;
  }

  unsigned long now = millis();
  if (now - lastSendTime >= SEND_INTERVAL) {
    lastSendTime = now;

    float humidity = 0;
    float temperature = 0;

    if (!readSensorData(temperature, humidity)) {
      return;
    }

    if (pollSessionStatus()) {
      sendSensorData(temperature, humidity);
    } else {
      Serial.println("⏸  No active session. Waiting for user to start...");
      allRelaysOff();
    }
  }
}

// ── CHECK SESSION STATUS ──────────────────────────────────
bool pollSessionStatus() {
  HTTPClient http;
  String pollURL = sessionStatusURL;
  pollURL += "&access_code=" + urlEncode(espAccessCode);
  pollURL += "&model_unit=" + urlEncode(modelUnit);
  pollURL += "&model_code=" + urlEncode(modelUnitCode);
  pollURL += "&device_unique_code=" + urlEncode(deviceUniqueCode);

  http.begin(wifiClient, pollURL);
  http.setTimeout(5000);

  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    Serial.print("Session: ");
    Serial.println(payload);

    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, payload);

    if (!err && doc["status"] == "success") {
      JsonVariant data = doc["data"];
      String status = data["status"].as<String>();

      if (status == "Running" || status == "RUNNING") {
        currentSessionId = data["session_id"] | 0;
        sessionRunning = currentSessionId > 0;
        if (sessionRunning) {
          Serial.print("✅ Active session_id: ");
          Serial.println(currentSessionId);
          http.end();
          return true;
        }
      }
    }
  } else {
    Serial.print("pollSessionStatus error: ");
    Serial.println(httpCode);
  }

  sessionRunning = false;
  currentSessionId = 0;
  http.end();
  return false;
}

bool readSensorData(float &temperature, float &humidity) {
  humidity = dht.readHumidity();
  temperature = dht.readTemperature();

  if (isnan(humidity) || isnan(temperature)) {
    Serial.println("❌ DHT11 read failed. Check wiring on D2.");
    return false;
  }

  Serial.print("🌡 Temp: "); Serial.print(temperature);
  Serial.print("°C  💧 Humidity: "); Serial.print(humidity); Serial.println("%");

  return true;
}

void sendSensorData(float temperature, float humidity) {
  if (currentSessionId <= 0) {
    Serial.println("⏸  No session_id available for logging.");
    return;
  }

  HTTPClient http;
  http.begin(wifiClient, logReadingURL);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(8000);

  // FIX 9: session_id is now included in the POST
  String postData = "action=log_reading";
  postData += "&session_id=" + String(currentSessionId);
  postData += "&temp="       + String(temperature, 2);
  postData += "&humidity="   + String(humidity, 2);
  postData += "&access_code=" + urlEncode(espAccessCode);
  postData += "&model_unit=" + urlEncode(modelUnit);
  postData += "&model_code="  + urlEncode(modelUnitCode);
  postData += "&device_unique_code=" + urlEncode(deviceUniqueCode);

  int httpCode = http.POST(postData);

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    Serial.print("Server: "); Serial.println(payload);

    StaticJsonDocument<512> doc;
    DeserializationError err = deserializeJson(doc, payload);

    if (!err && doc["status"] == "success") {
      int heater      = doc["data"]["heater"]       | 0;
      int exhaust     = doc["data"]["exhaust"]      | 0;
      int fan         = doc["data"]["fan"]          | 0;
      bool autoStop   = doc["data"]["auto_stopped"] | false;
      bool fishReady  = doc["data"]["fish_ready"]   | false;
      String phase    = doc["data"]["phase"]        | "Idle";

      // Apply relay states from server decision
      controlRelays(heater, exhaust, fan);

      Serial.print("Phase: "); Serial.print(phase);
      Serial.print("  H:"); Serial.print(heater);
      Serial.print(" E:"); Serial.print(exhaust);
      Serial.print(" F:"); Serial.println(fan);

      if (autoStop) {
        Serial.println("🚨 EMERGENCY AUTO-STOP by server!");
        allRelaysOff();
        sessionRunning    = false;
        currentSessionId = 0;
      }

      if (fishReady) {
        Serial.println("🎉 FISH IS READY! Targets reached.");
      }
    }
  } else {
    Serial.print("POST error: "); Serial.println(httpCode);
  }

  http.end();
}

// ── RELAY HELPERS ─────────────────────────────────────────
void controlRelays(int heater, int exhaust, int fan) {
  digitalWrite(HEATER_PIN,  heater  ? HIGH : LOW);
  digitalWrite(EXHAUST_PIN, exhaust ? HIGH : LOW);
  digitalWrite(FAN_PIN,     fan     ? HIGH : LOW);
}

void allRelaysOff() {
  digitalWrite(HEATER_PIN,  LOW);
  digitalWrite(EXHAUST_PIN, LOW);
  digitalWrite(FAN_PIN,     LOW);
}

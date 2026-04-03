// ============================================================
//  Smart Fish Drying System — ESP8266 + DHT11
//  FIXED VERSION — all 11 issues corrected
// ============================================================

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>   // FIX 5: Added — needed to POST to your server
#include <DHT.h>
#include <ArduinoJson.h>         // FIX 6: Added — needed to read server responses
                                 // Install: Arduino IDE → Manage Libraries → ArduinoJson by Benoit Blanchon

// ── PIN DEFINITIONS ───────────────────────────────────────
#define DHTPIN      D2   // DHT11 data pin
#define DHTTYPE     DHT11

#define HEATER_PIN  D5   // FIX 8: Relay for Heater
#define EXHAUST_PIN D6   // FIX 8: Relay for Exhaust Fan
#define FAN_PIN     D7   // FIX 8: Relay for Cooling Fan

// ── WIFI CREDENTIALS ─────────────────────────────────────
const char* ssid     = "Coherence 4g";
const char* password = "3628eb4bfC";

// ── SERVER CONFIGURATION ─────────────────────────────────
// FIX 1: Removed the accidental space before the IP
// FIX 2: Changed from router IP (192.168.100.1) to your PC's IP (192.168.100.39)
const char* serverIP = "192.168.100.39";  // ✅ Your PC's correct IP (no space!)

// FIX 3: Completed the URLs — no more "?..." placeholder
String logReadingURL   = "http://192.168.100.39/capstone/session_api.php";
String fetchControlURL = "http://192.168.100.39/capstone/controls_api.php?action=fetch_controls";
String fetchSessionURL = "http://192.168.100.39/capstone/session_api.php?action=poll_session_status";

// ── TIMING ───────────────────────────────────────────────
unsigned long lastSendTime = 0;
const unsigned long SEND_INTERVAL = 5000;  // Send every 5 seconds

// ── SESSION STATE ─────────────────────────────────────────
// FIX 9: Added session tracking — log_reading requires a session_id
int  currentSessionId = 0;
bool systemRunning    = false;

// ── OBJECTS ──────────────────────────────────────────────
DHT dht(DHTPIN, DHTTYPE);
WiFiClient wifiClient;

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

    // Step 1: Check if a session is running on the server
    fetchControls();

    // Step 2: Only send sensor data if a session is active
    if (systemRunning && currentSessionId > 0) {
      sendSensorData();   // FIX 10: This function now actually exists
    } else {
      Serial.println("⏸  No active session. Waiting for user to start...");
      allRelaysOff();
    }
  }
}

// ── FETCH CONTROLS FROM SERVER ────────────────────────────
// FIX 7 & 10: This function now actually makes an HTTP request
void fetchControls() {
  HTTPClient http;
  http.begin(wifiClient, fetchControlURL);
  http.setTimeout(5000);

  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    Serial.print("Controls: ");
    Serial.println(payload);

    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, payload);

    if (!err && doc["status"] == "success") {
      String status = doc["data"]["status"].as<String>();

      if (status == "RUNNING") {
        systemRunning = true;
        fetchActiveSessionId();   // FIX 10: Get the session_id
      } else {
        // status is STOPPED — turn off everything
        systemRunning = false;
        currentSessionId = 0;
        allRelaysOff();
      }
    }
  } else {
    Serial.print("fetchControls error: ");
    Serial.println(httpCode);
  }

  http.end();
}

// ── FETCH ACTIVE SESSION ID ───────────────────────────────
// FIX 10: This function was missing — now implemented
void fetchActiveSessionId() {
  HTTPClient http;
  http.begin(wifiClient, fetchSessionURL);
  http.setTimeout(5000);

  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, payload);

    if (!err && doc["status"] == "success") {
      if (doc["data"].containsKey("session_id")) {
        currentSessionId = doc["data"]["session_id"].as<int>();
        Serial.print("✅ Active session_id: ");
        Serial.println(currentSessionId);
      }
    }
  }

  http.end();
}

// ── SEND SENSOR DATA TO SERVER ────────────────────────────
// FIX 7 & 10: This function was missing — now implemented
// POSTs temp + humidity → server returns relay commands
void sendSensorData() {
  float humidity    = dht.readHumidity();
  float temperature = dht.readTemperature();

  if (isnan(humidity) || isnan(temperature)) {
    Serial.println("❌ DHT11 read failed. Check wiring on D2.");
    return;
  }

  Serial.print("🌡 Temp: "); Serial.print(temperature);
  Serial.print("°C  💧 Humidity: "); Serial.print(humidity); Serial.println("%");

  HTTPClient http;
  http.begin(wifiClient, logReadingURL);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(8000);

  // FIX 9: session_id is now included in the POST
  String postData = "action=log_reading";
  postData += "&session_id=" + String(currentSessionId);
  postData += "&temp="       + String(temperature, 2);
  postData += "&humidity="   + String(humidity, 2);

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
        systemRunning    = false;
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

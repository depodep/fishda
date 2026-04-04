// ============================================================
//  Smart Fish Drying System — ESP8266 + DHT11
//  AUTO SCHEDULE VERSION (NO POLLING)
// ============================================================

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <DHT.h>
#include <ArduinoJson.h>

// ── PIN DEFINITIONS ───────────────────────────────────────
#define DHTPIN      D4
#define DHTTYPE     DHT11

#define HEATER_PIN  D5
#define EXHAUST_PIN D6
#define FAN_PIN     D7

// ── WIFI ──────────────────────────────────────────────────
const char* ssid     = "Marcelino123";
const char* password = "marcelino@wifi";

// ── DEVICE INFO ───────────────────────────────────────────
const String espAccessCode = "APS-ESP-2026";
const String modelUnit = "Fishda";
const String modelUnitCode  = "FD2026";
const String deviceUniqueCode = "ESP8266-UNIT-001";

// ── SERVER ────────────────────────────────────────────────
const char* serverIP = "192.168.1.100";
const String baseURL = String("http://") + serverIP + "/fishda";
String logReadingURL = baseURL + "/api/session_api.php";

// ── TIMING ────────────────────────────────────────────────
unsigned long lastSendTime = 0;
const unsigned long SEND_INTERVAL = 5000;

// ── FAILSAFE ──────────────────────────────────────────────
unsigned long lastSuccessfulServerContact = 0;
const unsigned long FAILSAFE_TIMEOUT = 20000;

// ── GLOBALS ───────────────────────────────────────────────
DHT dht(DHTPIN, DHTTYPE);
WiFiClient wifiClient;

// ── FUNCTION DECLARATIONS ──────────────────────────────────
void reconnectWiFi();
bool readSensorData(float &temperature, float &humidity);
void sendSensorData(float temperature, float humidity);
void logSensorData(float temperature, float humidity, int sessionId);
void controlRelays(int heater, int exhaust, int fan);
void allRelaysOff();

// ── URL ENCODE ────────────────────────────────────────────
String urlEncode(const String& value) {
  String encoded = "";
  char c;
  char hex[4];
  for (unsigned int i = 0; i < value.length(); i++) {
    c = value.charAt(i);
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
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

  pinMode(HEATER_PIN, OUTPUT);  digitalWrite(HEATER_PIN, LOW);
  pinMode(EXHAUST_PIN, OUTPUT); digitalWrite(EXHAUST_PIN, LOW);
  pinMode(FAN_PIN, OUTPUT);     digitalWrite(FAN_PIN, LOW);

  dht.begin();

  Serial.println("\nConnecting to WiFi...");
  WiFi.begin(ssid, password);

  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }

  Serial.println("\n✅ WiFi Connected!");
  Serial.print("Arduino IP: ");
  Serial.println(WiFi.localIP());
  Serial.print("Server URL: ");
  Serial.println(baseURL);
}

// ── LOOP ──────────────────────────────────────────────────
void loop() {
  reconnectWiFi();

  unsigned long now = millis();

  // FAILSAFE
  if (millis() - lastSuccessfulServerContact > FAILSAFE_TIMEOUT) {
    Serial.println("⚠️ Server timeout! Turning OFF all relays.");
    allRelaysOff();
  }

  if (now - lastSendTime >= SEND_INTERVAL) {
    lastSendTime = now;

    float temperature = 0;
    float humidity = 0;

    if (!readSensorData(temperature, humidity)) return;

    sendSensorData(temperature, humidity);
  }
}

// ── WIFI RECONNECT ────────────────────────────────────────
void reconnectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.println("Reconnecting WiFi...");
  WiFi.disconnect();
  WiFi.begin(ssid, password);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n✅ Reconnected!");
  } else {
    Serial.println("\n❌ Reconnect failed");
  }
}

// ── SENSOR READ (WITH RETRY) ───────────────────────────────
bool readSensorData(float &temperature, float &humidity) {
  for (int i = 0; i < 3; i++) {
    humidity = dht.readHumidity();
    temperature = dht.readTemperature();

    if (!isnan(humidity) && !isnan(temperature)) {
      Serial.print("🌡 Temp: "); Serial.print(temperature);
      Serial.print("°C  💧 Humidity: "); Serial.print(humidity);
      Serial.print("%");
      
      // Add relay status output
      Serial.print(" | 🔌 Relays → H:");
      Serial.print(digitalRead(HEATER_PIN) ? 1 : 0);
      Serial.print(" E:");
      Serial.print(digitalRead(EXHAUST_PIN) ? 1 : 0);
      Serial.print(" F:");
      Serial.print(digitalRead(FAN_PIN) ? 1 : 0);
      Serial.println();
      
      return true;
    }
    delay(500);
  }

  Serial.println("❌ DHT11 read failed on D4.");
  return false;
}

// ── SEND DATA + CHECK SESSIONS ───────────────────────────
void sendSensorData(float temperature, float humidity) {
  HTTPClient http;
  
  // First, update live sensor cache (always works, no session needed)
  Serial.println("📤 Updating sensor cache...");
  http.begin(wifiClient, baseURL + "/api/sensor_api.php");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(8000);

  String postData = "temp=" + String(temperature, 2);
  postData += "&humidity=" + String(humidity, 2);

  int httpCode = http.POST(postData);
  
  if (httpCode == HTTP_CODE_OK) {
    Serial.println("✅ Sensor cache updated");
  } else {
    Serial.print("⚠️ Cache update failed: ");
    Serial.println(httpCode);
  }
  http.end();
  
  // Now check for active sessions and get control commands
  Serial.println("🔍 Checking for active sessions...");
  http.begin(wifiClient, baseURL + "/api/session_api.php");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(8000);

  postData = "action=poll_session_status";
  postData += "&access_code=" + urlEncode(espAccessCode);
  postData += "&model_unit=" + urlEncode(modelUnit);
  postData += "&model_code=" + urlEncode(modelUnitCode);
  postData += "&device_unique_code=" + urlEncode(deviceUniqueCode);

  httpCode = http.POST(postData);
  yield();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    Serial.print("Session Status: ");
    Serial.println(payload);
    
    lastSuccessfulServerContact = millis(); // Update success timestamp

    StaticJsonDocument<512> doc;
    DeserializationError err = deserializeJson(doc, payload);
    yield();

    if (!err && doc["status"] == "success") {
      JsonVariant data = doc["data"];
      String status = data["status"] | "Idle";
      
      if (status == "Running" || status == "RUNNING") {
        // Session is active, log the sensor data
        int sessionId = data["session_id"] | 0;
        if (sessionId > 0) {
          logSensorData(temperature, humidity, sessionId);
        }
      } else {
        Serial.println("⏸ No active session");
        allRelaysOff();
      }
    } else {
      Serial.println("❌ Invalid JSON response or error status");
    }
  } else {
    Serial.print("❌ Session check failed: ");
    Serial.println(httpCode);
    
    // Print additional error info
    if (httpCode == HTTPC_ERROR_CONNECTION_REFUSED) {
      Serial.println("   → Connection refused. Check server IP and port.");
    } else if (httpCode == HTTPC_ERROR_CONNECTION_LOST) {
      Serial.println("   → Connection lost during request.");
    } else if (httpCode == HTTPC_ERROR_READ_TIMEOUT) {
      Serial.println("   → Request timed out.");
    }
  }

  http.end();
}

// ── LOG SENSOR DATA TO ACTIVE SESSION ─────────────────────
void logSensorData(float temperature, float humidity, int sessionId) {
  HTTPClient http;
  http.begin(wifiClient, logReadingURL);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(8000);

  String postData = "action=log_reading";
  postData += "&session_id=" + String(sessionId);
  postData += "&temp=" + String(temperature, 2);
  postData += "&humidity=" + String(humidity, 2);
  postData += "&access_code=" + urlEncode(espAccessCode);
  postData += "&model_unit=" + urlEncode(modelUnit);
  postData += "&model_code=" + urlEncode(modelUnitCode);
  postData += "&device_unique_code=" + urlEncode(deviceUniqueCode);

  int httpCode = http.POST(postData);
  yield();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    Serial.print("✅ Logged: ");
    Serial.println(payload);

    StaticJsonDocument<512> doc;
    DeserializationError err = deserializeJson(doc, payload);
    yield();

    if (!err && doc["status"] == "success") {
      JsonVariant data = doc["data"];

      int heater  = data["heater"]  | 0;
      int exhaust = data["exhaust"] | 0;
      int fan     = data["fan"]     | 0;

      controlRelays(heater, exhaust, fan);

      Serial.print("🔌 Relays → H:");
      Serial.print(heater);
      Serial.print(" E:");
      Serial.print(exhaust);
      Serial.print(" F:");
      Serial.println(fan);
    }
  } else {
    Serial.print("❌ Log Error: ");
    Serial.println(httpCode);
  }

  http.end();
}

// ── RELAY CONTROL ─────────────────────────────────────────
void controlRelays(int heater, int exhaust, int fan) {
  digitalWrite(HEATER_PIN,  heater  ? HIGH : LOW);
  digitalWrite(EXHAUST_PIN, exhaust ? HIGH : LOW);
  digitalWrite(FAN_PIN,     fan     ? HIGH : LOW);
}

// ── ALL OFF ───────────────────────────────────────────────
void allRelaysOff() {
  digitalWrite(HEATER_PIN,  LOW);
  digitalWrite(EXHAUST_PIN, LOW);
  digitalWrite(FAN_PIN,     LOW);
}
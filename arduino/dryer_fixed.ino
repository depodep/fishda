 

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <DHT.h>
#include <ArduinoJson.h>

#define DHTPIN      D4
#define DHTTYPE     DHT11

#define FAN1_PIN    D6  
#define FAN2_PIN    D7

const char* ssid     = "Redmi Note 13";
const char* password = "aaaaaaaa";

const String espAccessCode = "APS-ESP-2026";
const String modelUnit = "Fishda";
const String modelUnitCode  = "FD2026";
const String deviceUniqueCode = "ESP8266-UNIT-001";

const char* serverIP = "10.18.239.71";
const String baseURL = String("http://") + serverIP + "/fishda";
String logReadingURL = baseURL + "/api/session_api.php";

unsigned long lastSendTime = 0;
const unsigned long SEND_INTERVAL = 3000; 

unsigned long lastSuccessfulServerContact = 0;
const unsigned long FAILSAFE_TIMEOUT = 20000;

int commErrorCount = 0;

DHT dht(DHTPIN, DHTTYPE);
WiFiClient wifiClient;


void reconnectWiFi();
bool readSensorData(float &temperature, float &humidity);
void sendSensorData(float temperature, float humidity);
void logSensorData(float temperature, float humidity, int sessionId);
void controlRelays(int fan1, int fan2);


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

void setup() {
  Serial.begin(115200);
  delay(100);

  // Initialize relays to OFF state (HIGH for ACTIVE LOW relay modules)
  pinMode(FAN1_PIN, OUTPUT);    digitalWrite(FAN1_PIN, HIGH);
  pinMode(FAN2_PIN, OUTPUT);    digitalWrite(FAN2_PIN, HIGH);

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


void loop() {
  reconnectWiFi();

  unsigned long now = millis();

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


bool readSensorData(float &temperature, float &humidity) {
  for (int i = 0; i < 3; i++) {
    humidity = dht.readHumidity();
    temperature = dht.readTemperature();

    if (!isnan(humidity) && !isnan(temperature)) {
      Serial.print("🌡 Temp: "); Serial.print(temperature);
      Serial.print("°C  💧 Humidity: "); Serial.print(humidity);
      Serial.print("%");
      
      Serial.print(" | 🔌 Relays → F1:");
      Serial.print(digitalRead(FAN1_PIN) ? 1 : 0);
      Serial.print(" F2:");
      Serial.print(digitalRead(FAN2_PIN) ? 1 : 0);
      Serial.println();
      
      return true;
    }
    delay(500);
  }

  Serial.println("❌ DHT11 read failed on D4.");
  return false;
}


void sendSensorData(float temperature, float humidity) {
  HTTPClient http;
  bool anyError = false;

  Serial.println("📤 PRIORITY: Updating sensor cache...");
  http.begin(wifiClient, baseURL + "/api/sensor_api.php");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(4000); 

  String postData = "temp=" + String(temperature, 2);
  postData += "&humidity=" + String(humidity, 2);

  int httpCode = http.POST(postData);
  
  if (httpCode == HTTP_CODE_OK) {
    Serial.println("✅ Sensor cache updated - DEVICE ONLINE (heartbeat)");
    lastSuccessfulServerContact = millis();
  } else {
    Serial.print("⚠️ Cache update failed: ");
    Serial.println(httpCode);
    anyError = true;
  }
  http.end();
  

  Serial.println("🔍 Checking for active sessions...");
  http.begin(wifiClient, baseURL + "/api/session_api.php");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(4000);

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
    
    lastSuccessfulServerContact = millis();  
    StaticJsonDocument<512> doc;
    DeserializationError err = deserializeJson(doc, payload);
    yield();

    if (!err && doc["status"] == "success") {
      JsonVariant data = doc["data"];
      
      // Check if data is empty array (no active sessions found)
      if (data.isNull() || (data.is<JsonArray>() && data.size() == 0)) {
        Serial.println("⏸ No active session found - keeping data flow, relays OFF");
        allRelaysOff();
        logSensorData(temperature, humidity, 0);
      } else {
        // Session data exists - extract fields
        String status = data["status"] | "Idle";
        String command = data["command"] | "RUN";
        int sessionId = data["session_id"] | 0;

        // Check if session is active: either status is Running OR command suggests activity
        if ((status == "Running" || status == "RUNNING") || (command == "RUN" && sessionId > 0)) {
          Serial.println("✅ Active session detected - logging data");
          if (sessionId > 0) {
            logSensorData(temperature, humidity, sessionId);
          }
        } else if (command == "COOLDOWN") {
          // ── COOLDOWN MODE: Keep fans OFF for 5 minutes ──
          Serial.println("❄️ COOLDOWN COMMAND - Fans OFF");
          allRelaysOff();
          // Still log data during cooldown
          if (sessionId > 0) {
            logSensorData(temperature, humidity, sessionId);
          }
        } else {
          // ── NO ACTIVE SESSION: Keep fans OFF BUT STILL LOG DATA for live display ──
          Serial.println("⏸ Session inactive - keeping data flow, relays OFF");
          allRelaysOff();
          // Log as session_id=0 for idle state (used for live display)
          logSensorData(temperature, humidity, 0);
        }
      }
    } else {
      Serial.println("❌ Invalid JSON response or error status");
      // Fallback: keep logging idle data
      allRelaysOff();
      logSensorData(temperature, humidity, 0);
    }
  } else {
    Serial.print("❌ Session check failed: ");
    Serial.println(httpCode);
    anyError = true;

    if (httpCode == HTTPC_ERROR_CONNECTION_REFUSED) {
      Serial.println("   → Connection refused. Check server IP and port.");
    } else if (httpCode == HTTPC_ERROR_CONNECTION_LOST) {
      Serial.println("   → Connection lost during request.");
    } else if (httpCode == HTTPC_ERROR_READ_TIMEOUT) {
      Serial.println("   → Request timed out.");
    }
  }

  http.end();

  // ── Communication failsafe: 10 consecutive errors → turn everything OFF ──
  if (anyError) {
    commErrorCount++;
    Serial.print("⚠️ Comm error count: ");
    Serial.println(commErrorCount);
    if (commErrorCount >= 10) {
      Serial.println("🚨 FAILSAFE: 10 consecutive errors, turning all relays OFF");
      allRelaysOff();
    }
  } else {
    // Reset error counter on any successful full cycle
    if (commErrorCount > 0) {
      Serial.println("✅ Communication restored, resetting error counter");
    }
    commErrorCount = 0;
  }
}


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

      String command = data["command"] | "RUN";
      int fan1 = data["fan1"] | 0;
      int fan2 = data["fan2"] | 0;

      // If COOLDOWN command, turn off all fans
      if (command == "COOLDOWN") {
        fan1 = 0;
        fan2 = 0;
      }

      controlRelays(fan1, fan2);


      Serial.print("📊 Server Commands → F1:");
      Serial.print(fan1);
      Serial.print(" F2:");
      Serial.print(fan2);
      Serial.print(" | Phase: ");
      Serial.print(data["phase"] | "Unknown");
      Serial.print(" | Cmd: ");
      Serial.println(command);
    }
  } else {
    Serial.print("❌ Log Error: ");
    Serial.println(httpCode);
  }

  http.end();
}


void controlRelays(int fan1, int fan2) {
  // ACTIVE LOW relay: 1=ON (LOW), 0=OFF (HIGH)
  digitalWrite(FAN1_PIN, fan1 ? LOW : HIGH);
  digitalWrite(FAN2_PIN, fan2 ? LOW : HIGH);
}


void allRelaysOff() {
  // ACTIVE LOW relay: HIGH = OFF
  digitalWrite(FAN1_PIN, HIGH);
  digitalWrite(FAN2_PIN, HIGH);
}
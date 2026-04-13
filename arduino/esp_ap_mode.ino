// ============================================================
//  Smart Fish Drying System — ESP8266 AP MODE
//  ESP creates its own WiFi network for direct connection
//  Laptop connects directly to ESP without router
// ============================================================

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>    
#include <DHT.h>
#include <ArduinoJson.h>         

// ── PIN DEFINITIONS ───────────────────────────────────────
#define DHTPIN      D4  // DHT11 data pin
#define DHTTYPE     DHT11

#define HEATER_PIN  D5   
#define EXHAUST_PIN D6  
#define FAN_PIN     D7    

// ── ACCESS POINT CONFIGURATION ────────────────────────────
// ESP will create WiFi network with these credentials
const char* ap_ssid     = "Fingerprintscans";      // WiFi name
const char* ap_password = "fingerpassword";         // WiFi password (min 8 chars)

// Static IP configuration for ESP (this will be the server IP)
IPAddress local_IP(192, 168, 4, 1);       // ESP IP address
IPAddress gateway(192, 168, 4, 1);        // Gateway (same as ESP)
IPAddress subnet(255, 255, 255, 0);       // Subnet mask

// ── DEVICE IDENTITY / ACCESS ─────────────────────────────
const String espAccessCode = "APS-ESP-2026";
const String modelUnit = "Fishda";
const String modelUnitCode  = "FD2026";
const String deviceUniqueCode = "ESP8266-UNIT-001";

// ── SERVER CONFIGURATION ─────────────────────────────────
// IMPORTANT: Change this to your laptop's IP on the AP network
// When laptop connects to ESP WiFi, it will get IP like 192.168.4.2
// But the server (XAMPP) runs on localhost, so use 192.168.4.2
const char* serverIP = "192.168.4.2";  // Laptop IP when connected to ESP
const String baseURL = String("http://") + serverIP + "/fishda";
String logReadingURL = baseURL + "/api/sensor_api.php";
String sessionStatusURL = baseURL + "/api/session_api.php?action=poll_session_status";

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

  // Initialize relay pins as OUTPUT, default OFF
  pinMode(HEATER_PIN,  OUTPUT); digitalWrite(HEATER_PIN,  LOW);
  pinMode(EXHAUST_PIN, OUTPUT); digitalWrite(EXHAUST_PIN, LOW);
  pinMode(FAN_PIN,     OUTPUT); digitalWrite(FAN_PIN,     LOW);

  dht.begin();

  // Configure Access Point with static IP
  Serial.println("\n🔧 Configuring Access Point...");
  
  if (!WiFi.softAPConfig(local_IP, gateway, subnet)) {
    Serial.println("❌ AP Config Failed!");
  }
  
  // Start Access Point
  if (WiFi.softAP(ap_ssid, ap_password)) {
    Serial.println("✅ Access Point Started!");
    Serial.print("📡 WiFi Network: ");
    Serial.println(ap_ssid);
    Serial.print("🔑 Password: ");
    Serial.println(ap_password);
    Serial.print("🌐 ESP IP Address: ");
    Serial.println(WiFi.softAPIP());
    Serial.println("\n📱 Connect your laptop to this WiFi network!");
    Serial.println("⚙️  Then access: http://192.168.4.2/fishda/");
  } else {
    Serial.println("❌ Failed to start Access Point!");
  }
}

// ── MAIN LOOP ─────────────────────────────────────────────
void loop() {
  // Check if any clients are connected
  if (WiFi.softAPgetStationNum() == 0) {
    Serial.println("⏸  Waiting for laptop to connect to WiFi...");
    delay(5000);
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

    if (!pollSessionStatus()) {
      Serial.println("⏸  No active session. Sending heartbeat for scheduled auto-start...");
    }
    // Always send sensor payload so backend can auto-start due schedules and keep heartbeat alive.
    sendSensorData(temperature, humidity);
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
  HTTPClient http;
  http.begin(wifiClient, logReadingURL);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(8000);

  String postData = "action=log_reading";
  postData += "&session_id=" + String(currentSessionId > 0 ? currentSessionId : 0);
  postData += "&temp=" + String(temperature, 2);
  postData += "&humidity=" + String(humidity, 2);
  postData += "&access_code=" + urlEncode(espAccessCode);
  postData += "&model_unit=" + urlEncode(modelUnit);
  postData += "&model_code=" + urlEncode(modelUnitCode);
  postData += "&device_unique_code=" + urlEncode(deviceUniqueCode);

  int httpCode = http.POST(postData);

  if (httpCode == HTTP_CODE_OK) {
    String response = http.getString();
    Serial.print("✅ Sent: ");
    Serial.println(response);

    StaticJsonDocument<512> doc;
    DeserializationError err = deserializeJson(doc, response);

    if (!err && doc["status"] == "success") {
      JsonObject data = doc["data"];

      int heater  = data["heater"]  | 0;
      int exhaust = data["exhaust"] | 0;
      int fan     = data["fan"]     | 0;

      digitalWrite(HEATER_PIN,  heater  ? HIGH : LOW);
      digitalWrite(EXHAUST_PIN, exhaust ? HIGH : LOW);
      digitalWrite(FAN_PIN,     fan     ? HIGH : LOW);

      Serial.print("🔌 Relays → Heater:");
      Serial.print(heater);
      Serial.print(" Exhaust:");
      Serial.print(exhaust);
      Serial.print(" Fan:");
      Serial.println(fan);
    }
  } else {
    Serial.print("❌ HTTP Error: ");
    Serial.println(httpCode);
  }

  http.end();
}

void allRelaysOff() {
  digitalWrite(HEATER_PIN,  LOW);
  digitalWrite(EXHAUST_PIN, LOW);
  digitalWrite(FAN_PIN,     LOW);
}

// ============================================================
//  fish_dryer_esp8266_UPDATED.ino
//  Smart Fish Drying System — ESP8266 + DHT11
//
//  KEY CHANGES vs original:
//   1. FAN is the heat source — ON during "Heating" phase
//   2. New command "COOLDOWN" → all relays OFF for 5 min
//   3. After cooldown, server sends "RUN" again → fan turns back ON
//   4. Serial monitor prints cooldown countdown
//
//  Wiring:
//    DHT11 DATA  → D4  (GPIO2)
//    EXHAUST relay → D2  (GPIO4)   — active HIGH
//    FAN relay   → D5  (GPIO14)   — active HIGH  ← HEAT SOURCE
//    (HEATER_PIN D1 reserved but not used if fan is your only heat)
//    Change RELAY_ACTIVE_HIGH to false if your module is active-LOW
// ============================================================

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <DHT.h>
#include <ArduinoJson.h>   // ArduinoJson by Benoit Blanchon (Library Manager)

// ── WiFi Credentials ─────────────────────────────────────────
const char* WIFI_SSID     = "JOY";          // <-- your WiFi SSID
const char* WIFI_PASSWORD = "12345678";     // <-- your WiFi password

// ── Server URL ───────────────────────────────────────────────
const char* SERVER_URL = "http://10.31.184.71/fishda/api/sensor_api.php";
// Replace 172.21.194.71 with your PC's local IP

// ── DHT11 Sensor ─────────────────────────────────────────────
#define DHTPIN  2         // D4 on NodeMCU
#define DHTTYPE DHT11
DHT dht(DHTPIN, DHTTYPE);

// ── Relay Pins ───────────────────────────────────────────────
#define EXHAUST_PIN 4     // D2 — exhaust fan / vent
#define FAN_PIN     14    // D5 — circulation fan = HEAT SOURCE
#define HEATER_PIN  5     // D1 — reserved (not used if fan is heat source)
#define RELAY_ACTIVE_HIGH true  // false if relay triggers on LOW

// ── Timing ───────────────────────────────────────────────────
const unsigned long READ_INTERVAL = 10000;  // 10 seconds
unsigned long lastReadTime = 0;

// ── State ────────────────────────────────────────────────────
bool    systemRunning  = false;
bool    inCooldown     = false;
unsigned long cooldownRemainingMs = 0;
unsigned long cooldownStartMs     = 0;

// ── Relay helper ─────────────────────────────────────────────
void setRelay(int pin, bool on) {
  if (RELAY_ACTIVE_HIGH) {
    digitalWrite(pin, on ? HIGH : LOW);
  } else {
    digitalWrite(pin, on ? LOW : HIGH);
  }
}

void allRelaysOff() {
  setRelay(HEATER_PIN,  false);
  setRelay(EXHAUST_PIN, false);
  setRelay(FAN_PIN,     false);
}

// ── Setup ─────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.println("\n=== Smart Fish Dryer (Fan=Heat Source) ===");

  pinMode(HEATER_PIN,  OUTPUT);
  pinMode(EXHAUST_PIN, OUTPUT);
  pinMode(FAN_PIN,     OUTPUT);
  allRelaysOff();  // Safe default on boot

  dht.begin();

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("Connecting to WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi connected! IP: " + WiFi.localIP().toString());
}

// ── Main Loop ────────────────────────────────────────────────
void loop() {
  unsigned long now = millis();

  // ── WiFi reconnect guard ──────────────────────────────────
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] Disconnected. Reconnecting...");
    allRelaysOff();  // Safety: off while reconnecting
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    delay(3000);
    return;
  }

  if (now - lastReadTime >= READ_INTERVAL) {
    lastReadTime = now;

    // ── Read DHT11 ──────────────────────────────────────────
    float humidity    = dht.readHumidity();
    float temperature = dht.readTemperature();

    if (isnan(humidity) || isnan(temperature)) {
      Serial.println("[DHT11] Read failed! Check wiring on D4.");
      return;
    }

    Serial.printf("[DHT11] Temp: %.1f°C  Humidity: %.1f%%\n", temperature, humidity);

    // ── POST to sensor_api.php ──────────────────────────────
    WiFiClient client;
    HTTPClient http;

    String payload = "temp=" + String(temperature, 1)
                   + "&humidity=" + String(humidity, 1);

    http.begin(client, SERVER_URL);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    http.setTimeout(8000);

    int httpCode = http.POST(payload);
    Serial.printf("[HTTP] Code: %d\n", httpCode);

    if (httpCode == 200) {
      String response = http.getString();
      Serial.println("[HTTP] Body: " + response);

      // ── Parse JSON ────────────────────────────────────────
      StaticJsonDocument<512> doc;
      DeserializationError err = deserializeJson(doc, response);

      if (!err && doc["status"] == "success") {
        const char* command = doc["data"]["command"] | "STOP";
        int  exhaust        = doc["data"]["exhaust"] | 0;
        int  fan            = doc["data"]["fan"]     | 0;
        int  heater         = doc["data"]["heater"]  | 0;  // reserved
        const char* phase   = doc["data"]["phase"]   | "Idle";
        float target_temp   = doc["data"]["target_temp"] | 0.0;
        float target_hum    = doc["data"]["target_hum"]  | 0.0;
        bool fish_ready     = doc["data"]["fish_ready"]  | false;
        int  cooldown_rem   = doc["data"]["cooldown_remaining"] | 0;

        Serial.printf("[Control] CMD=%s  Phase=%s\n", command, phase);
        Serial.printf("[Control] Target: %.1f°C / %.1f%%  FishReady=%s\n",
          target_temp, target_hum, fish_ready ? "YES" : "no");

        // ── Handle command ───────────────────────────────────
        if (strcmp(command, "STOP") == 0) {
          // Emergency or manual stop
          allRelaysOff();
          systemRunning = false;
          inCooldown    = false;
          Serial.println("[System] STOPPED.");

        } else if (strcmp(command, "COOLDOWN") == 0) {
          // 5-minute cooldown after target reached — all OFF
          allRelaysOff();
          inCooldown    = true;
          systemRunning = false;
          Serial.printf("[System] COOLDOWN — %d seconds remaining.\n", cooldown_rem);

          if (fish_ready) {
            Serial.println("[System] 🎉 Target reached! Cooling down 5 min, then resuming.");
          }

        } else {
          // RUN — apply relay states from server
          // FAN = heat source (server sets fan=1 during Heating phase)
          // EXHAUST = overheat venting
          systemRunning = true;
          inCooldown    = false;

          setRelay(FAN_PIN,     fan     == 1);   // fan is heat source
          setRelay(EXHAUST_PIN, exhaust == 1);   // exhaust for overheat
          setRelay(HEATER_PIN,  heater  == 1);   // reserved

          Serial.printf("[Relays] Fan=%s  Exhaust=%s  Phase=%s\n",
            fan == 1 ? "ON" : "OFF",
            exhaust == 1 ? "ON" : "OFF",
            phase);

          // Print alerts
          if (doc["data"]["alert"] && !doc["data"]["alert"].isNull()) {
            const char* alertLevel = doc["data"]["alert"]["level"] | "";
            const char* alertMsg   = doc["data"]["alert"]["message"] | "";
            Serial.printf("[ALERT][%s] %s\n", alertLevel, alertMsg);
          }
        }

      } else {
        Serial.println("[HTTP] JSON parse error or server error — relays OFF (safe).");
        allRelaysOff();
      }

    } else {
      Serial.printf("[HTTP] Error %d — server unreachable. Relays OFF.\n", httpCode);
      allRelaysOff();  // fail-safe
    }

    http.end();
  }
}

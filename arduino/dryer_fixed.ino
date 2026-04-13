          // ============================================================
          //  Smart Fish Drying System — ESP8266 AP MODE (Dryer Fixed)
          //  Synced with esp_ap_mode flow, adapted for dual FAN outputs
          // ============================================================

          #include <ESP8266WiFi.h>
          #include <ESP8266HTTPClient.h>
          #include <DHT.h>
          #include <ArduinoJson.h>

          #define DHTPIN      D4
          #define DHTTYPE     DHT11

          #define FAN1_PIN    D6
          #define FAN2_PIN    D7

          // STA (router) mode
          const char* sta_ssid     = "Redmi Note 13";
          const char* sta_password = "aaaaaaaa";

          const String espAccessCode = "APS-ESP-2026";
          const String modelUnit = "Fishda";
          const String modelUnitCode  = "FD2026";
          const String deviceUniqueCode = "ESP8266-UNIT-001";

          const char* serverIP = "10.129.229.71";
          const String baseURL = String("http://") + serverIP + "/fishda";
          String logReadingURL = baseURL + "/api/session_api.php";
          String sessionStatusURL = baseURL + "/api/session_api.php?action=poll_session_status";

          unsigned long lastSendTime = 0;
          const unsigned long SEND_INTERVAL = 5000;
          unsigned long lastWaitLog = 0;
          unsigned long lastStaRetry = 0;

          int currentSessionId = 0;
          bool sessionRunning = false;

          DHT dht(DHTPIN, DHTTYPE);
          WiFiClient wifiClient;

          bool readSensorData(float &temperature, float &humidity);
          bool pollSessionStatus();
          void sendSensorData(float temperature, float humidity);
          void controlRelays(int fan1, int fan2);
          void allRelaysOff();
          void connectSTA();


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

            // ACTIVE LOW relays: HIGH = OFF
            pinMode(FAN1_PIN, OUTPUT); digitalWrite(FAN1_PIN, HIGH);
            pinMode(FAN2_PIN, OUTPUT); digitalWrite(FAN2_PIN, HIGH);

            dht.begin();

            WiFi.mode(WIFI_STA);
            connectSTA();
          }


          void connectSTA() {
            Serial.print("Connecting WiFi: ");
            Serial.println(sta_ssid);
            WiFi.begin(sta_ssid, sta_password);

            int attempts = 0;
            while (WiFi.status() != WL_CONNECTED && attempts < 20) {
              delay(500);
              Serial.print(".");
              attempts++;
            }

            if (WiFi.status() == WL_CONNECTED) {
              Serial.println("\nWiFi connected");
              Serial.print("ESP IP: ");
              Serial.println(WiFi.localIP());
            } else {
              Serial.println("\nWiFi not connected yet, will keep retrying in loop.");
            }
          }


          void loop() {
            unsigned long now = millis();

            // Retry STA connect every 10s if disconnected.
            if (WiFi.status() != WL_CONNECTED && (now - lastStaRetry >= 10000)) {
              lastStaRetry = now;
              Serial.println("Retrying WiFi connection...");
              WiFi.begin(sta_ssid, sta_password);
            }

            if (WiFi.status() != WL_CONNECTED) {
              if (now - lastWaitLog >= 5000) {
                lastWaitLog = now;
                Serial.println("Waiting connect... (STA not connected)");
              }
              delay(200);
              return;
            }

            if (now - lastSendTime >= SEND_INTERVAL) {
              lastSendTime = now;
              
              float humidity = 0;
              float temperature = 0;

              if (!readSensorData(temperature, humidity)) {
                return;
              }

              if (!pollSessionStatus()) {
                Serial.println("No active session. Sending heartbeat/idle log...");
              }

              // Always send so backend can keep live state and auto-start schedules.
              sendSensorData(temperature, humidity);
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

            Serial.println("DHT11 read failed on D4.");
            return false;
          }

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
                    Serial.print("Active session_id: ");
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
              Serial.print("Sent: ");
              Serial.println(response);

              StaticJsonDocument<512> doc;
              DeserializationError err = deserializeJson(doc, response);

              if (!err && doc["status"] == "success") {
                JsonObject data = doc["data"];

                int fan1 = data["fan1"] | 0;
                int fan2 = data["fan2"] | 0;

                String command = data["command"] | "RUN";
                if (command == "COOLDOWN") {
                  fan1 = 0;
                  fan2 = 0;
                }

                controlRelays(fan1, fan2);

                Serial.print("Relays -> F1:");
                Serial.print(fan1);
                Serial.print(" F2:");
                Serial.print(fan2);
                Serial.print(" | phase: ");
                Serial.print(data["phase"] | "Unknown");
                Serial.print(" | cmd: ");
                Serial.println(command);
              } else {
                // Idle / invalid response fallback
                allRelaysOff();
              }
            } else {
              Serial.print("HTTP Error: ");
              Serial.println(httpCode);
              allRelaysOff();
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
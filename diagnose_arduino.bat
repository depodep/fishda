@echo off
echo === Arduino Connection Diagnostic ===
echo.

echo 1. Checking network configuration...
ipconfig | findstr /i "IPv4"
echo.

echo 2. Testing if Apache is listening on port 80...
netstat -an | findstr ":80 "
echo.

echo 3. Testing local API access...
curl -X POST "http://localhost/fishda/api/session_api.php" ^
     -H "Content-Type: application/x-www-form-urlencoded" ^
     -d "action=poll_session_status&access_code=APS-ESP-2026&model_unit=Fishda&model_code=FD2026&device_unique_code=ESP8266-UNIT-001" ^
     -w "HTTP Status: %%{http_code}\n" ^
     --connect-timeout 5 ^
     --max-time 10
echo.

echo 4. Testing Arduino target IP (192.168.1.100)...
ping -n 1 192.168.1.100
echo.

echo 5. Checking Windows Firewall status...
netsh advfirewall show allprofiles state
echo.

echo === Diagnosis Complete ===
echo.
echo SOLUTIONS:
echo - If Apache not listening: Start XAMPP Apache service
echo - If IP mismatch: Update Arduino serverIP to match your actual IP
echo - If firewall blocking: Add Apache exception to Windows Firewall
echo - If network different: Ensure Arduino and PC on same WiFi network

pause
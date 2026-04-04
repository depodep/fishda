<?php
// Arduino Connectivity Test Tool
header('Content-Type: text/plain');

echo "=== Arduino Connectivity Test ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Test 1: Basic API endpoint
echo "1. Testing session_api.php directly:\n";
try {
    require_once 'database/dbcon.php';
    echo "   ✅ Database connection OK\n";
    
    // Simulate Arduino request
    $_POST['action'] = 'poll_session_status';
    $_POST['access_code'] = 'APS-ESP-2026';
    $_POST['model_unit'] = 'Fishda';
    $_POST['model_code'] = 'FD2026';
    $_POST['device_unique_code'] = 'ESP8266-UNIT-001';
    
    ob_start();
    include 'api/session_api.php';
    $api_response = ob_get_clean();
    
    echo "   API Response: $api_response\n";
    
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}

echo "\n2. Testing sensor_api.php:\n";
try {
    $_POST = []; // Clear previous POST
    $_POST['temp'] = '25.5';
    $_POST['humidity'] = '60.2';
    
    ob_start();
    include 'api/sensor_api.php';
    $sensor_response = ob_get_clean();
    
    echo "   Sensor API Response: $sensor_response\n";
    
} catch (Exception $e) {
    echo "   ❌ Sensor API Error: " . $e->getMessage() . "\n";
}

echo "\n3. Network Interface Information:\n";
$hostname = gethostname();
echo "   Hostname: $hostname\n";

$local_ip = gethostbyname($hostname);
echo "   Resolved IP: $local_ip\n";

echo "\n4. Troubleshooting Steps:\n";
echo "   a) Verify Arduino WiFi connection\n";
echo "   b) Check if 192.168.1.100 is correct server IP\n";
echo "   c) Test with: curl -X POST http://192.168.1.100/fishda/api/session_api.php -d \"action=poll_session_status&access_code=APS-ESP-2026&model_unit=Fishda&model_code=FD2026&device_unique_code=ESP8266-UNIT-001\"\n";
echo "   d) Ensure Windows Firewall allows Apache\n";
echo "   e) Check XAMPP Apache is running on port 80\n";

?>
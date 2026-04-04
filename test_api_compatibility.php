<?php
// API Compatibility Test Script
// Tests if Arduino authentication and API endpoints work after reorganization

echo "=== Arduino API Compatibility Test ===\n\n";

// Test 1: Check if API files exist
echo "1. Checking API file accessibility:\n";
$api_files = [
    'session_api.php' => 'c:\xampp\htdocs\fishda\api\session_api.php',
    'sensor_api.php' => 'c:\xampp\htdocs\fishda\api\sensor_api.php',
    'controls_api.php' => 'c:\xampp\htdocs\fishda\api\controls_api.php'
];

foreach ($api_files as $name => $path) {
    if (file_exists($path)) {
        echo "   ✓ $name exists\n";
    } else {
        echo "   ✗ $name MISSING at $path\n";
    }
}

// Test 2: Simulate Arduino ESP authentication
echo "\n2. Testing ESP authentication:\n";

// Simulate POST data from Arduino
$_POST = [
    'action' => 'poll_session_status',
    'access_code' => 'APS-ESP-2026',
    'model_unit' => 'Fishda', 
    'model_code' => 'FD2026',
    'device_unique_code' => 'ESP8266-UNIT-001'
];

// Capture output
ob_start();
try {
    include 'api/session_api.php';
    $session_response = ob_get_clean();
    echo "   ✓ Session API Response: $session_response\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "   ✗ Session API Error: " . $e->getMessage() . "\n";
}

// Test 3: Test sensor data logging (needs valid session)
echo "\n3. Testing sensor data logging:\n";

$_POST = [
    'action' => 'log_reading',
    'session_id' => 1,
    'temp' => 25.5,
    'humidity' => 60.2,
    'access_code' => 'APS-ESP-2026',
    'model_unit' => 'Fishda',
    'model_code' => 'FD2026', 
    'device_unique_code' => 'ESP8266-UNIT-001'
];

ob_start();
try {
    include 'api/sensor_api.php';
    $sensor_response = ob_get_clean();
    echo "   ✓ Sensor API Response: $sensor_response\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "   ✗ Sensor API Error: " . $e->getMessage() . "\n";
}

// Test 4: Check Arduino code compatibility
echo "\n4. Checking Arduino code versions:\n";

$arduino_files = [
    'esp_ap_mode.ino' => 'arduino/esp_ap_mode.ino',
    'sketch_apr2b.ino' => 'arduino/sketch_apr2b/sketch_apr2b.ino',
    'arduinocoe.cpp' => 'arduino/arduinocoe.cpp'
];

foreach ($arduino_files as $name => $path) {
    if (file_exists($path)) {
        $content = file_get_contents($path);
        
        // Check for authentication parameters
        $has_access_code = strpos($content, 'access_code') !== false;
        $has_model_unit = strpos($content, 'model_unit') !== false || strpos($content, 'modelUnit') !== false;
        $has_device_code = strpos($content, 'device_unique_code') !== false || strpos($content, 'deviceUniqueCode') !== false;
        
        // Check for correct API paths
        $has_correct_paths = strpos($content, '/api/') !== false;
        
        echo "   $name:\n";
        echo "     - Authentication: " . ($has_access_code && $has_model_unit && $has_device_code ? "✓ Complete" : "✗ Missing") . "\n";
        echo "     - API Paths: " . ($has_correct_paths ? "✓ Updated" : "✗ Old paths") . "\n";
        
        // Check if it's actual code or JSON data
        if (strpos($content, '#include') === false && strpos($content, '{') === 0) {
            echo "     - File Type: ✗ Contains JSON data, not Arduino code\n";
        } else {
            echo "     - File Type: ✓ Valid Arduino code\n";
        }
    } else {
        echo "   $name: ✗ Not found\n";
    }
}

echo "\n=== Compatibility Summary ===\n";

// Check database connection
try {
    require_once 'database/dbcon.php';
    echo "✓ Database connection works\n";
    
    // Check if prototype exists
    $stmt = $dbh->prepare("SELECT id, model_name, given_code FROM tbl_prototypes WHERE model_name=? AND given_code=?");
    $stmt->execute(['Fishda', 'FD2026']);
    $proto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($proto) {
        echo "✓ Prototype 'Fishda' with code 'FD2026' exists in database\n";
    } else {
        echo "✗ Prototype 'Fishda' with code 'FD2026' not found in database\n";
    }
    
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
}

?>
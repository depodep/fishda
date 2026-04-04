<?php
// Test Arduino connection issues
echo "=== Arduino Connection Debug ===\n\n";

// Test 1: Check if APIs are accessible locally
echo "1. Testing local API accessibility:\n";
$test_url = "http://localhost/fishda/api/session_api.php";
echo "   Testing: $test_url\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $test_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'action' => 'poll_session_status',
    'access_code' => 'APS-ESP-2026',
    'model_unit' => 'Fishda',
    'model_code' => 'FD2026', 
    'device_unique_code' => 'ESP8266-UNIT-001'
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "   ❌ CURL Error: $error\n";
} else {
    echo "   ✅ HTTP $http_code: $response\n";
}

// Test 2: Check network info
echo "\n2. Network Information:\n";
echo "   Server IP in Arduino: 192.168.1.100\n";
echo "   Arduino IP: 192.168.1.106\n";

// Get server's actual IP addresses
$hostname = gethostname();
$local_ips = gethostbynamel($hostname);
echo "   Computer hostname: $hostname\n";

if ($local_ips) {
    echo "   Computer IPs: " . implode(', ', $local_ips) . "\n";
} else {
    echo "   Computer IPs: Could not determine\n";
}

// Test 3: Check if XAMPP is accessible on different IPs
echo "\n3. Testing API on different IPs:\n";

$test_ips = ['localhost', '127.0.0.1', '192.168.1.100'];
if ($local_ips) {
    $test_ips = array_merge($test_ips, $local_ips);
}

foreach (array_unique($test_ips) as $ip) {
    $test_url = "http://$ip/fishda/api/session_api.php";
    echo "   Testing: $ip ... ";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $test_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'action=poll_session_status&access_code=APS-ESP-2026&model_unit=Fishda&model_code=FD2026&device_unique_code=ESP8266-UNIT-001');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "❌ $error\n";
    } else {
        if ($http_code == 200 && strpos($response, '"status"') !== false) {
            echo "✅ Working!\n";
        } else {
            echo "⚠️ HTTP $http_code\n";
        }
    }
}

// Test 4: Check Arduino action compatibility
echo "\n4. Testing Arduino actions:\n";

$arduino_actions = ['log_reading', 'auto_control', 'poll_session_status'];
foreach ($arduino_actions as $action) {
    echo "   Testing action '$action' ... ";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost/fishda/api/session_api.php");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'action' => $action,
        'access_code' => 'APS-ESP-2026',
        'model_unit' => 'Fishda',
        'model_code' => 'FD2026',
        'device_unique_code' => 'ESP8266-UNIT-001',
        'session_id' => 1,
        'temp' => 25.5,
        'humidity' => 60.2
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "❌ $error\n";
    } else {
        $json = json_decode($response, true);
        if ($json && isset($json['status'])) {
            if ($json['status'] === 'success') {
                echo "✅ Success\n";
            } else {
                echo "⚠️ " . ($json['message'] ?? 'Error') . "\n";
            }
        } else {
            echo "❌ Invalid response\n";
        }
    }
}

echo "\n=== Diagnosis Complete ===\n";
?>
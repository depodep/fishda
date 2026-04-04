<?php
// Network diagnostic tool for Arduino connection issues

echo "=== Network Diagnostic Tool ===\n\n";

// Get Windows network configuration
echo "1. Network Configuration:\n";
$ipconfig_output = shell_exec('ipconfig');
echo $ipconfig_output . "\n";

echo "2. Testing port 80 availability:\n";
$netstat_output = shell_exec('netstat -an | findstr :80');
echo $netstat_output ? $netstat_output : "Port 80 not listening\n";

echo "\n3. Testing local API access:\n";
$test_url = "http://localhost/fishda/api/session_api.php";

$postData = http_build_query([
    'action' => 'poll_session_status',
    'access_code' => 'APS-ESP-2026',
    'model_unit' => 'Fishda', 
    'model_code' => 'FD2026',
    'device_unique_code' => 'ESP8266-UNIT-001'
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $postData,
        'timeout' => 10
    ]
]);

$response = file_get_contents($test_url, false, $context);
if ($response !== false) {
    echo "✅ API Response: $response\n";
} else {
    echo "❌ API call failed\n";
}

echo "\n4. Windows Firewall Status:\n";
$firewall_output = shell_exec('netsh advfirewall show currentprofile');
echo $firewall_output . "\n";

echo "\n5. Recommendations:\n";
echo "- Check if XAMPP is running (Apache service)\n";
echo "- Verify Windows Firewall allows Apache/Port 80\n";
echo "- Confirm Arduino and PC are on same network (192.168.1.x)\n";
echo "- Test with: ping 192.168.1.100 from Arduino network\n";

?>
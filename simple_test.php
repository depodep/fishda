<?php
// Simple Arduino Connection Test
try {
    echo "=== Arduino Connection Test ===\n\n";
    
    // Test basic connectivity
    echo "1. Testing basic PHP execution: ✅ OK\n\n";
    
    // Get network info
    echo "2. Network Information:\n";
    $hostname = gethostname();
    echo "   Hostname: $hostname\n";
    
    // Try to get IP addresses
    $local_ip = gethostbyname($hostname);
    echo "   Primary IP: $local_ip\n";
    
    // Check if we can determine the actual local IP
    $output = shell_exec('ipconfig | findstr "IPv4"');
    if ($output) {
        echo "   Network interfaces:\n";
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (trim($line)) {
                echo "   " . trim($line) . "\n";
            }
        }
    }
    
    echo "\n3. Testing API endpoints:\n";
    
    // Test sensor API
    echo "   Testing sensor_api.php... ";
    if (file_exists('api/sensor_api.php')) {
        echo "✅ File exists\n";
    } else {
        echo "❌ File missing\n";
    }
    
    // Test session API  
    echo "   Testing session_api.php... ";
    if (file_exists('api/session_api.php')) {
        echo "✅ File exists\n";
    } else {
        echo "❌ File missing\n";
    }
    
    echo "\n4. Arduino Configuration Check:\n";
    echo "   Arduino expects server at: 192.168.1.100\n";
    echo "   Arduino WiFi network: Marcelino123\n";
    
    if ($local_ip === '192.168.1.100') {
        echo "   ✅ IP matches Arduino configuration\n";
    } else {
        echo "   ⚠️  IP mismatch! Arduino trying to reach 192.168.1.100\n";
        echo "       But server appears to be at: $local_ip\n";
        echo "       SOLUTION: Update Arduino serverIP to \"$local_ip\"\n";
    }
    
    echo "\n5. Next Steps:\n";
    echo "   a) Ensure XAMPP Apache is running\n";
    echo "   b) Update Arduino IP if needed\n";
    echo "   c) Upload fixed Arduino code\n";
    echo "   d) Monitor serial output for connection success\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
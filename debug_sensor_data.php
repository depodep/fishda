<?php
// Debug: Check sensor data and stop endpoint
require_once 'database/dbcon.php';

echo "<h1>🔍 Sensor Data & Stop Button Debug</h1>";

try {
    // 1. Check sensor_readings table exists and has data
    echo "<h2>1️⃣ Sensor Readings Table</h2>";
    
    $readingsCount = $dbh->query("SELECT COUNT(*) FROM sensor_readings")->fetchColumn();
    echo "<p><strong>Total Sensor Readings:</strong> $readingsCount</p>";
    
    if ($readingsCount > 0) {
        echo "<h3>Recent Sensor Data (Last 10):</h3>";
        $recent = $dbh->query("
            SELECT timestamp, temperature, humidity 
            FROM sensor_readings 
            ORDER BY timestamp DESC 
            LIMIT 10
        ")->fetchAll();
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Timestamp</th><th>Temperature</th><th>Humidity</th></tr>";
        foreach($recent as $r) {
            echo "<tr>";
            echo "<td>{$r['timestamp']}</td>";
            echo "<td>{$r['temperature']}°C</td>";
            echo "<td>{$r['humidity']}%</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p><strong>⚠️ NO SENSOR DATA</strong> - This is why the graph is empty!</p>";
    }
    
    // 2. Check live_sensor_cache table
    echo "<h2>2️⃣ Live Sensor Cache</h2>";
    
    $cacheCount = $dbh->query("SELECT COUNT(*) FROM live_sensor_cache")->fetchColumn();
    echo "<p><strong>Cache Entries:</strong> $cacheCount</p>";
    
    if ($cacheCount > 0) {
        $cache = $dbh->query("SELECT * FROM live_sensor_cache LIMIT 5")->fetchAll();
        foreach($cache as $c) {
            echo "<p>Temperature: {$c['temperature']}°C, Humidity: {$c['humidity']}%, Time: {$c['last_update']}</p>";
        }
    }
    
    // 3. Check drying_controls status
    echo "<h2>3️⃣ Drying Controls Status</h2>";
    
    $control = $dbh->query("SELECT * FROM drying_controls WHERE id = 1")->fetch();
    if ($control) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Status</th><th>Target Temp</th><th>Target Humidity</th><th>Start Time</th></tr>";
        echo "<tr>";
        echo "<td>{$control['status']}</td>";
        echo "<td>{$control['target_temp']}°C</td>";
        echo "<td>{$control['target_humidity']}%</td>";
        echo "<td>" . ($control['start_time'] ?: 'NULL') . "</td>";
        echo "</tr>";
        echo "</table>";
    }
    
    // 4. Check running sessions
    echo "<h2>4️⃣ Running Sessions</h2>";
    
    $running = $dbh->query("SELECT COUNT(*) FROM drying_sessions WHERE status = 'Running'")->fetchColumn();
    echo "<p><strong>Running Sessions:</strong> $running</p>";
    
    // 5. Test the API endpoints
    echo "<h2>5️⃣ API Endpoint Tests</h2>";
    
    echo "<p>";
    echo "<a href='api/controls_api.php?action=fetch_controls' target='_blank' style='background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px; display: inline-block;'>🧪 Test fetch_controls</a>";
    echo "&nbsp;";
    echo "<a href='api/controls_api.php?action=get_live_sensor' target='_blank' style='background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px; display: inline-block;'>📊 Test get_live_sensor</a>";
    echo "&nbsp;";
    echo "<a href='api/controls_api.php?action=get_sensor_history&limit=20' target='_blank' style='background: #17a2b8; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px; display: inline-block;'>📈 Test get_sensor_history</a>";
    echo "</p>";
    
    // 6. Simulate stop API call
    echo "<h2>6️⃣ Stop API Test</h2>";
    
    echo "<form method='POST' style='background: #fff3cd; padding: 15px; border-radius: 5px;'>";
    echo "<input type='hidden' name='test_stop' value='1'>";
    echo "<p>⚠️ This will stop any running session!</p>";
    echo "<button type='submit' style='background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>🛑 Test Stop API</button>";
    echo "</form>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}

// Handle test stop
if ($_POST['test_stop'] ?? false) {
    try {
        $dbh->query("UPDATE drying_sessions SET status='Completed', end_time=NOW() WHERE status='Running'");
        $dbh->query("UPDATE drying_controls SET status='STOPPED', start_time=NULL WHERE id=1");
        
        echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h3>✅ Stop API Test Successful</h3>";
        echo "<p>Sessions stopped and drying_controls updated.</p>";
        echo "</div>";
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h3>❌ Error</h3>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
}

echo "<p><a href='admin/users_dashboard.php'>← Back to Dashboard</a></p>";
?>
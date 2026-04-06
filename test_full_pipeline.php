<?php
// test_full_pipeline.php - Complete sensor data pipeline diagnostics
header('Content-Type: text/html; charset=utf-8');

require_once 'database/dbcon.php';

echo "<h2>🔍 Full Sensor Data Pipeline Test</h2>";

// 1. Check if sensor_readings table exists and has data
echo "<h3>1. Sensor Readings Table</h3>";
try {
    $result = $dbh->query("
        SELECT COUNT(*) as cnt FROM sensor_readings
    ");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "<p>Sensor readings count: <strong>" . $row['cnt'] . "</strong></p>";
    
    if ($row['cnt'] > 0) {
        $latest = $dbh->query("
            SELECT * FROM sensor_readings ORDER BY timestamp DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Session ID</th><th>Temperature</th><th>Humidity</th><th>Timestamp</th></tr>";
        foreach ($latest as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['session_id'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['temperature'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['humidity'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['timestamp'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p><strong style='color:red;'>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 2. Check live_sensor_cache
echo "<h3>2. Live Sensor Cache Table</h3>";
try {
    $result = $dbh->query("
        SELECT * FROM live_sensor_cache WHERE id = 1
    ");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Temperature</th><th>Humidity</th><th>Timestamp</th></tr>";
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['temperature'] ?? 'NULL') . "°C</td>";
        echo "<td>" . htmlspecialchars($row['humidity'] ?? 'NULL') . "%</td>";
        echo "<td>" . htmlspecialchars($row['timestamp'] ?? 'NULL') . "</td>";
        echo "</tr>";
        echo "</table>";
        
        // Check if timestamp is recent
        if (!empty($row['timestamp'])) {
            $ts = strtotime($row['timestamp']);
            $now = time();
            $diff = $now - $ts;
            echo "<p>Last update: <strong>" . ($diff < 60 ? "$diff seconds ago" : ($diff < 3600 ? round($diff/60) . " minutes ago" : round($diff/3600) . " hours ago")) . "</strong></p>";
        }
    } else {
        echo "<p><strong style='color:orange;'>⚠️ No live sensor cache record found (ID=1)</strong></p>";
    }
} catch (Exception $e) {
    echo "<p><strong style='color:red;'>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 3. Check drying_sessions
echo "<h3>3. Drying Sessions</h3>";
try {
    $running = $dbh->query("
        SELECT COUNT(*) as cnt FROM drying_sessions WHERE status='Running'
    ")->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>Running sessions: <strong>" . $running['cnt'] . "</strong></p>";
    
    // Get latest sessions
    $latest = $dbh->query("
        SELECT * FROM drying_sessions ORDER BY start_time DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>User</th><th>Status</th><th>Start</th><th>End</th><th>Set Temp</th><th>Set Humidity</th></tr>";
    foreach ($latest as $row) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['session_id'] ?? $row['id'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['user_id'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['status'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['start_time'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['end_time'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['set_temp'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['set_humidity'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p><strong style='color:red;'>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 4. Check drying_controls
echo "<h3>4. Drying Controls</h3>";
try {
    $row = $dbh->query("SELECT * FROM drying_controls WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr>";
        foreach ($row as $k => $v) {
            echo "<th>" . htmlspecialchars($k) . "</th>";
        }
        echo "</tr>";
        echo "<tr>";
        foreach ($row as $k => $v) {
            echo "<td>" . htmlspecialchars($v ?? 'NULL') . "</td>";
        }
        echo "</tr>";
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p><strong style='color:red;'>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 5. Test API calls directly
echo "<h3>5. Test API Endpoints</h3>";
echo "<p><a href='../api/controls_api.php?action=get_live_sensor' target='_blank'>GET /api/controls_api.php?action=get_live_sensor</a></p>";
echo "<p><a href='../api/controls_api.php?action=fetch_controls' target='_blank'>GET /api/controls_api.php?action=fetch_controls</a></p>";

// 6. Check database structure
echo "<h3>6. Database Schema Check</h3>";
try {
    $tables = ['sensor_readings', 'live_sensor_cache', 'drying_sessions', 'drying_controls'];
    foreach ($tables as $table) {
        $stmt = $dbh->query("SHOW CREATE TABLE `$table`");
        if ($stmt) {
            echo "<details>";
            echo "<summary><strong>$table</strong></summary>";
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<pre>" . htmlspecialchars($row['Create Table']) . "</pre>";
            echo "</details>";
        }
    }
} catch (Exception $e) {
    echo "<p><strong style='color:red;'>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr><p><em>Last updated: " . date('Y-m-d H:i:s') . "</em></p>";
?>

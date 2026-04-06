<?php
// quick_sensor_check.php - Quick diagnostics
require_once 'database/dbcon.php';

echo "<h2>Quick Sensor Check</h2>";

// 1. Count drying_logs
$logs = $dbh->query("SELECT COUNT(*) as cnt FROM drying_logs")->fetch()['cnt'];
echo "<p><strong>drying_logs records:</strong> $logs</p>";

// 2. Count sensor_readings  
$readings = $dbh->query("SELECT COUNT(*) as cnt FROM sensor_readings")->fetch()['cnt'];
echo "<p><strong>sensor_readings records:</strong> $readings</p>";

// 3. Check live_sensor_cache
$live = $dbh->query("SELECT * FROM live_sensor_cache WHERE id=1")->fetch(PDO::FETCH_ASSOC);
echo "<p><strong>live_sensor_cache latest:</strong></p>";
echo "<pre>";
print_r($live);
echo "</pre>";

// 4. Check if there are any running sessions
$running = $dbh->query("SELECT COUNT(*) as cnt FROM drying_sessions WHERE status='Running'")->fetch()['cnt'];
echo "<p><strong>Running sessions:</strong> $running</p>";

// 5. Check drying_controls status
$ctrl = $dbh->query("SELECT * FROM drying_controls WHERE id=1")->fetch(PDO::FETCH_ASSOC);
echo "<p><strong>drying_controls:</strong></p>";
echo "<pre>";
print_r($ctrl);
echo "</pre>";

// 6. Test API calls
echo "<h3>API Endpoints</h3>";
echo "<p><a href='api/controls_api.php?action=get_live_sensor' target='_blank'>Test: get_live_sensor</a></p>";
echo "<p><a href='api/session_api.php?action=get_live_data' target='_blank'>Test: get_live_data</a></p>";
?>

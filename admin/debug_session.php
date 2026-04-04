<?php
/**
 * Session Status Debug Tool
 * Quick test to see what the session API returns
 */

// Start session to get prototype info
session_start();

// Get prototype info from session
$proto_id = $_SESSION['proto_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

echo "<h1>Session Status Debug</h1>";
echo "<p><strong>User ID:</strong> " . ($user_id ?: 'Not logged in') . "</p>";
echo "<p><strong>Prototype ID:</strong> " . ($proto_id ?: 'Not set') . "</p>";

if (!$proto_id) {
    echo "<p style='color: red;'>⚠️ No prototype ID in session. Please log in properly.</p>";
    exit;
}

// Test the API endpoint directly
$api_url = "http://localhost/fishda/api/session_api.php?action=get_live_data&proto_id=" . $proto_id;
echo "<h2>API Endpoint Test</h2>";
echo "<p><strong>URL:</strong> <a href='$api_url' target='_blank'>$api_url</a></p>";

// Make the API call
$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'header' => "Cookie: " . $_SERVER['HTTP_COOKIE'] ?? ""
    ]
]);

$response = file_get_contents($api_url, false, $context);

if ($response === false) {
    echo "<p style='color: red;'>❌ API call failed</p>";
} else {
    echo "<h3>API Response:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px; overflow: auto;'>";
    
    $decoded = json_decode($response, true);
    if ($decoded) {
        echo json_encode($decoded, JSON_PRETTY_PRINT);
        
        // Check for session data
        if ($decoded['status'] === 'success' && isset($decoded['data']['session_id'])) {
            echo "\n\n✅ <strong>Session Found!</strong>\n";
            echo "Session ID: " . $decoded['data']['session_id'] . "\n";
            echo "Status: " . ($decoded['data']['status'] ?? 'Unknown') . "\n";
            echo "Set Temp: " . ($decoded['data']['set_temp'] ?? 'Unknown') . "°C\n";
            echo "Set Humidity: " . ($decoded['data']['set_humidity'] ?? 'Unknown') . "%\n";
        } else {
            echo "\n\n❌ <strong>No Active Session</strong>\n";
            echo "Reason: " . ($decoded['message'] ?? 'Unknown') . "\n";
        }
    } else {
        echo htmlspecialchars($response);
    }
    echo "</pre>";
}

// Also check database directly
require_once '../database/dbcon.php';

echo "<h2>Database Check</h2>";
try {
    $stmt = $dbh->prepare("SELECT session_id, user_id, status, set_temp, set_humidity, start_time FROM drying_sessions WHERE status = 'Running' ORDER BY start_time DESC LIMIT 5");
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($sessions)) {
        echo "<p>❌ No running sessions found in database</p>";
    } else {
        echo "<p>✅ Found " . count($sessions) . " running session(s):</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Session ID</th><th>User ID</th><th>Status</th><th>Temp</th><th>Humidity</th><th>Start Time</th></tr>";
        
        foreach ($sessions as $session) {
            echo "<tr>";
            echo "<td>" . $session['session_id'] . "</td>";
            echo "<td>" . $session['user_id'] . "</td>";
            echo "<td>" . $session['status'] . "</td>";
            echo "<td>" . $session['set_temp'] . "°C</td>";
            echo "<td>" . $session['set_humidity'] . "%</td>";
            echo "<td>" . $session['start_time'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Check if any of these sessions belong to current user
        $user_sessions = array_filter($sessions, function($s) use ($user_id) {
            return $s['user_id'] == $user_id;
        });
        
        if (empty($user_sessions)) {
            echo "<p style='color: orange;'>⚠️ No running sessions belong to current user (ID: $user_id)</p>";
        } else {
            echo "<p style='color: green;'>✅ Found " . count($user_sessions) . " session(s) for current user</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
}

echo "<br><br>";
echo "<a href='users_dashboard.php'>← Back to Dashboard</a>";
?>
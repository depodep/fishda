<?php
// Check what sessions actually exist and their status
require_once 'database/dbcon.php';

echo "<h1>🔍 Complete Session Status Check</h1>";

try {
    // Get ALL recent sessions
    echo "<h2>📊 All Recent Sessions (Last 50):</h2>";
    $allSessions = $dbh->query("
        SELECT session_id, start_time, end_time, status, user_id, schedule_id,
               CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info
        FROM drying_sessions ds
        LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
        ORDER BY start_time DESC
        LIMIT 50
    ")->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
    echo "<tr style='background: #f5f5f5;'><th>ID</th><th>Device</th><th>Start Time</th><th>End Time</th><th>Status</th><th>Schedule</th></tr>";
    
    foreach($allSessions as $s) {
        $statusColor = '';
        switch($s['status']) {
            case 'Running': $statusColor = 'background: #3b82f6; color: white;'; break;
            case 'Completed': $statusColor = 'background: #10b981; color: white;'; break;
            case 'Interrupted': $statusColor = 'background: #f97316; color: white;'; break;
        }
        
        echo "<tr>";
        echo "<td><strong>{$s['session_id']}</strong></td>";
        echo "<td>{$s['device_info']}</td>";
        echo "<td>{$s['start_time']}</td>";
        echo "<td>" . ($s['end_time'] ?: '—') . "</td>";
        echo "<td style='$statusColor padding: 3px 8px;'><strong>{$s['status']}</strong></td>";
        echo "<td>" . ($s['schedule_id'] ?: '—') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Count by status
    echo "<h2>📈 Session Status Summary:</h2>";
    $summary = $dbh->query("
        SELECT status, COUNT(*) as count 
        FROM drying_sessions 
        GROUP BY status
    ")->fetchAll();
    
    echo "<ul>";
    foreach($summary as $stat) {
        echo "<li><strong>{$stat['status']}:</strong> {$stat['count']} sessions</li>";
    }
    echo "</ul>";
    
    // Check specifically for session IDs in the 80s range
    echo "<h2>🎯 Sessions 80-90 (around #88):</h2>";
    $range = $dbh->query("
        SELECT session_id, start_time, end_time, status
        FROM drying_sessions 
        WHERE session_id BETWEEN 80 AND 90
        ORDER BY session_id
    ")->fetchAll();
    
    if (count($range) > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Session ID</th><th>Start</th><th>End</th><th>Status</th></tr>";
        foreach($range as $r) {
            $highlight = $r['session_id'] == 88 ? 'background: #ffffcc;' : '';
            echo "<tr style='$highlight'>";
            echo "<td><strong>#{$r['session_id']}</strong></td>";
            echo "<td>{$r['start_time']}</td>";
            echo "<td>" . ($r['end_time'] ?: 'NULL') . "</td>";
            echo "<td><strong>{$r['status']}</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No sessions found in the 80-90 range.</p>";
    }
    
    // Check the highest session ID
    $maxSession = $dbh->query("SELECT MAX(session_id) as max_id FROM drying_sessions")->fetch();
    echo "<p><strong>Highest Session ID in database:</strong> {$maxSession['max_id']}</p>";
    
    // Check if there are any NULL statuses
    $nullStatus = $dbh->query("SELECT COUNT(*) FROM drying_sessions WHERE status IS NULL OR status = ''")->fetchColumn();
    if ($nullStatus > 0) {
        echo "<p style='color: #dc3545;'><strong>⚠️ Warning:</strong> $nullStatus sessions have NULL or empty status!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<h2>🔧 Actions:</h2>";
echo "<p><a href='api/calendar_fixed.php' target='_blank' style='background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px;'>🧪 Test Calendar API</a></p>";
echo "<p><a href='admin/users_dashboard.php' style='background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px;'>🏠 Back to Dashboard</a></p>";
?>
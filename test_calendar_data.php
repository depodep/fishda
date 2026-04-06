<?php
// Quick check: Are there any drying sessions to display?
require_once 'database/dbcon.php';

echo "<h1>🔍 Calendar Data Check</h1>";

try {
    // Check total sessions
    $total_sessions = $dbh->query("SELECT COUNT(*) FROM drying_sessions")->fetchColumn();
    $completed_sessions = $dbh->query("SELECT COUNT(*) FROM drying_sessions WHERE status IN ('Completed','Interrupted')")->fetchColumn();
    $schedules = $dbh->query("SELECT COUNT(*) FROM batch_schedules")->fetchColumn();
    
    echo "<h2>📊 Database Content:</h2>";
    echo "<ul>";
    echo "<li><strong>Total Sessions:</strong> $total_sessions</li>";
    echo "<li><strong>Completed/Interrupted Sessions:</strong> $completed_sessions</li>";
    echo "<li><strong>Batch Schedules:</strong> $schedules</li>";
    echo "</ul>";
    
    if ($completed_sessions > 0) {
        echo "<h3>✅ Recent Completed Sessions:</h3>";
        $recent = $dbh->query("
            SELECT ds.session_id, ds.start_time, ds.end_time, ds.status,
                   CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info
            FROM drying_sessions ds
            LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
            WHERE ds.status IN ('Completed','Interrupted')
            ORDER BY ds.start_time DESC
            LIMIT 10
        ");
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Session ID</th><th>Start Time</th><th>End Time</th><th>Status</th><th>Device Info</th></tr>";
        while ($row = $recent->fetch()) {
            $icon = $row['status'] === 'Completed' ? '✅' : '⚠️';
            echo "<tr>";
            echo "<td>{$row['session_id']}</td>";
            echo "<td>{$row['start_time']}</td>";
            echo "<td>" . ($row['end_time'] ?: 'N/A') . "</td>";
            echo "<td>$icon {$row['status']}</td>";
            echo "<td><strong>{$row['device_info']}</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p><strong>❌ No completed sessions found!</strong></p>";
        echo "<p>This is why the calendar is empty.</p>";
    }
    
    if ($schedules > 0) {
        echo "<h3>📅 Recent Schedules:</h3>";
        $sched = $dbh->query("
            SELECT id, title, sched_date, sched_time, status 
            FROM batch_schedules 
            ORDER BY sched_date DESC 
            LIMIT 5
        ");
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Title</th><th>Date</th><th>Time</th><th>Status</th></tr>";
        while ($row = $sched->fetch()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['title']}</td>";
            echo "<td>{$row['sched_date']}</td>";
            echo "<td>{$row['sched_time']}</td>";
            echo "<td>{$row['status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Database Error: " . $e->getMessage() . "</p>";
}

echo "<h2>🧪 Test Calendar API</h2>";
echo "<p><a href='api/schedule_api.php?action=get_calendar_events' target='_blank'>🔗 Test Calendar API Direct</a></p>";
echo "<p><a href='admin/users_dashboard.php'>← Back to Dashboard</a></p>";
?>
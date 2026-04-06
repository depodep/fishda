<?php
// Direct database check and calendar API test
require_once 'database/dbcon.php';

echo "<h1>🔍 Emergency Calendar Debug</h1>";

try {
    // 1. Check if there's any data at all
    echo "<h2>📊 Database Content Check</h2>";
    
    $total_sessions = $dbh->query("SELECT COUNT(*) FROM drying_sessions")->fetchColumn();
    $completed_sessions = $dbh->query("SELECT COUNT(*) FROM drying_sessions WHERE status IN ('Completed','Interrupted')")->fetchColumn();
    $all_statuses = $dbh->query("SELECT status, COUNT(*) as count FROM drying_sessions GROUP BY status")->fetchAll();
    
    echo "<p><strong>Total Sessions:</strong> $total_sessions</p>";
    echo "<p><strong>Completed/Interrupted:</strong> $completed_sessions</p>";
    
    echo "<h3>Session Status Breakdown:</h3>";
    foreach($all_statuses as $status) {
        echo "<p>- {$status['status']}: {$status['count']}</p>";
    }
    
    // 2. Show recent sessions
    if ($total_sessions > 0) {
        echo "<h3>📋 Recent Sessions (All Statuses):</h3>";
        $recent = $dbh->query("
            SELECT session_id, start_time, end_time, status, user_id, proto_id,
                   DATE(start_time) as date_only
            FROM drying_sessions 
            ORDER BY start_time DESC 
            LIMIT 10
        ");
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Date</th><th>Start Time</th><th>End Time</th><th>Status</th><th>User</th><th>Proto</th></tr>";
        while ($row = $recent->fetch()) {
            echo "<tr>";
            echo "<td>{$row['session_id']}</td>";
            echo "<td><strong>{$row['date_only']}</strong></td>";
            echo "<td>{$row['start_time']}</td>";
            echo "<td>" . ($row['end_time'] ?: 'Running') . "</td>";
            echo "<td><strong>{$row['status']}</strong></td>";
            echo "<td>{$row['user_id']}</td>";
            echo "<td>{$row['proto_id']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Test the calendar API directly
    echo "<h2>🧪 Calendar API Test</h2>";
    
    // Mock admin session for testing
    session_start();
    $_SESSION['permission'] = 'admin';
    $_SESSION['user_id'] = 1;
    
    // Replicate exact API logic
    $events = [];
    
    // Test schedules query
    $stmt = $dbh->prepare("SELECT bs.id, bs.title, bs.sched_date, bs.sched_time, bs.status, u.username FROM batch_schedules bs JOIN tblusers u ON u.id = bs.user_id ORDER BY bs.sched_date ASC");
    $stmt->execute([]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p><strong>Schedules Found:</strong> " . count($schedules) . "</p>";
    
    // Test sessions query  
    $stmtS = $dbh->prepare("
        SELECT ds.session_id, ds.start_time, ds.end_time, ds.status,
               CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info
        FROM drying_sessions ds
        JOIN tblusers u ON u.id = ds.user_id
        LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
        WHERE ds.status IN ('Completed','Interrupted')
        ORDER BY ds.start_time DESC
        LIMIT 20
    ");
    $stmtS->execute([]);
    $sessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Sessions Found for Calendar:</strong> " . count($sessions) . "</p>";
    
    if (count($sessions) > 0) {
        echo "<h3>✅ Sessions That Should Appear on Calendar:</h3>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Session ID</th><th>Start Time</th><th>Status</th><th>Device Info</th><th>Calendar Event</th></tr>";
        
        foreach($sessions as $row) {
            $color = $row['status'] === 'Completed' ? '#10b981' : '#f97316';
            $icon = $row['status'] === 'Completed' ? '✅' : '⚠️';
            $title = "$icon {$row['device_info']}";
            
            echo "<tr>";
            echo "<td>{$row['session_id']}</td>";
            echo "<td>{$row['start_time']}</td>";
            echo "<td><span style='background: $color; color: white; padding: 2px 5px; border-radius: 3px;'>{$row['status']}</span></td>";
            echo "<td>{$row['device_info']}</td>";
            echo "<td><strong>$title</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Create sample event structure
        $event = [
            'id' => 'sess_' . $sessions[0]['session_id'],
            'title' => ($sessions[0]['status'] === 'Completed' ? '✅ ' : '⚠️ ') . $sessions[0]['device_info'],
            'start' => $sessions[0]['start_time'],
            'backgroundColor' => $sessions[0]['status'] === 'Completed' ? '#10b981' : '#f97316',
        ];
        
        echo "<h3>📅 Sample Calendar Event JSON:</h3>";
        echo "<pre>" . json_encode($event, JSON_PRETTY_PRINT) . "</pre>";
        
    } else {
        echo "<p><strong>❌ No sessions found for calendar!</strong></p>";
        echo "<p>This explains why the calendar is empty.</p>";
        
        // Check what statuses we actually have
        $statusCheck = $dbh->query("SELECT DISTINCT status FROM drying_sessions")->fetchAll();
        echo "<h4>Available Statuses in DB:</h4>";
        foreach($statusCheck as $s) {
            echo "<p>- '{$s['status']}'</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Database Error:</strong> " . $e->getMessage() . "</p>";
}

echo "<h2>🔧 Quick Fix Options</h2>";
echo "<p><a href='api/schedule_api.php?action=get_calendar_events' target='_blank' style='background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;'>🧪 Test API Direct</a></p>";
echo "<p><a href='admin/users_dashboard.php' style='background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;'>🏠 Back to Dashboard</a></p>";
?>
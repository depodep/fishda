<?php
// Calendar Debug - Check why drying records aren't showing on calendar
require_once 'database/dbcon.php';
session_start();

echo "<h1>📅 Calendar Debug - Drying Records Display</h1>";

$is_admin = ($_SESSION['permission'] ?? 'user') === 'admin';
$user_id = $_SESSION['user_id'] ?? 0;

// Check current user session
echo "<h2>Current User Session</h2>";
echo "<ul>";
echo "<li><strong>User ID:</strong> " . $user_id . "</li>";
echo "<li><strong>Permission:</strong> " . ($_SESSION['permission'] ?? 'Not set') . "</li>";
echo "<li><strong>Username:</strong> " . ($_SESSION['username'] ?? 'Not set') . "</li>";
echo "<li><strong>Proto ID:</strong> " . ($_SESSION['proto_id'] ?? 'Not set') . "</li>";
echo "<li><strong>Is Admin:</strong> " . ($is_admin ? 'Yes' : 'No') . "</li>";
echo "</ul>";

echo "<h2>🔍 Testing Calendar Sessions Query</h2>";

// Replicate the exact calendar query
$whereClause = "WHERE ds.status IN ('Completed','Interrupted')";
$params = [];

if (!$is_admin) {
    $proto_id = intval($_SESSION['proto_id'] ?? $_GET['proto_id'] ?? $_POST['proto_id'] ?? 0);
    if ($proto_id > 0) {
        $whereClause .= " AND ds.proto_id = :proto_id";
        $params[':proto_id'] = $proto_id;
        echo "<p><strong>Filtering by Proto ID:</strong> $proto_id</p>";
    } else {
        $whereClause .= " AND 1=0";
        echo "<p><strong>⚠️ No Proto ID - No sessions will show!</strong></p>";
    }
} else {
    echo "<p><strong>Admin access - showing all sessions</strong></p>";
}

echo "<p><strong>WHERE clause:</strong> $whereClause</p>";
echo "<p><strong>Parameters:</strong> " . json_encode($params) . "</p>";

try {
    $stmtS = $dbh->prepare(
        "SELECT ds.session_id, ds.start_time, ds.end_time,
                ds.set_temp, ds.set_humidity, ds.status, ds.proto_id,
                u.username, u.permission,
                CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info,
                COALESCE(p.model_name, 'Fishda') AS model_name,
                COALESCE(p.given_code, 'FD2026') AS unit_code
         FROM drying_sessions ds
         JOIN tblusers u ON u.id = ds.user_id
         LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
         $whereClause
         ORDER BY ds.start_time DESC
         LIMIT 20"
    );
    $stmtS->execute($params);
    $sessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>✅ Found Sessions: " . count($sessions) . "</h3>";

    if (empty($sessions)) {
        echo "<div style='background: #ffebee; padding: 15px; border-radius: 5px; color: #c62828;'>";
        echo "<h3>❌ No sessions found for calendar!</h3>";
        
        // Check if we have any sessions at all
        $total = $dbh->query("SELECT COUNT(*) FROM drying_sessions")->fetchColumn();
        $completed = $dbh->query("SELECT COUNT(*) FROM drying_sessions WHERE status IN ('Completed','Interrupted')")->fetchColumn();
        
        echo "<p><strong>Total sessions in DB:</strong> $total</p>";
        echo "<p><strong>Completed/Interrupted sessions:</strong> $completed</p>";
        
        if ($completed > 0 && !$is_admin) {
            echo "<p><strong>🎯 Issue:</strong> Sessions exist but user doesn't have access to them</p>";
            echo "<p><strong>Solution:</strong> Set proto_id in user session or run as admin</p>";
            
            // Show what sessions exist
            $allSessions = $dbh->query("SELECT session_id, start_time, status, proto_id, user_id FROM drying_sessions WHERE status IN ('Completed','Interrupted') ORDER BY start_time DESC LIMIT 5")->fetchAll();
            echo "<h4>Available Sessions (need matching proto_id):</h4>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>ID</th><th>Start</th><th>Status</th><th>Proto ID</th><th>User</th></tr>";
            foreach($allSessions as $s) {
                echo "<tr><td>{$s['session_id']}</td><td>{$s['start_time']}</td><td>{$s['status']}</td><td>{$s['proto_id']}</td><td>{$s['user_id']}</td></tr>";
            }
            echo "</table>";
        }
        echo "</div>";
    } else {
        echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 5px;'>";
        echo "<h3>✅ Calendar Events That Will Be Created:</h3>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f5f5f5;'><th>Session ID</th><th>Start Time</th><th>Status</th><th>Calendar Title</th><th>Color</th></tr>";
        
        foreach($sessions as $row) {
            $color = $row['status'] === 'Completed' ? '#10b981' : '#f97316';
            $icon = $row['status'] === 'Completed' ? '✅' : '⚠️';
            $title = "$icon {$row['device_info']}";
            
            echo "<tr>";
            echo "<td>{$row['session_id']}</td>";
            echo "<td>{$row['start_time']}</td>";
            echo "<td><span style='background: $color; color: white; padding: 3px 8px; border-radius: 3px;'>{$row['status']}</span></td>";
            echo "<td><strong>$title</strong></td>";
            echo "<td><div style='width: 30px; height: 20px; background: $color; border-radius: 3px;'></div></td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<div style='background: #ffebee; padding: 15px; border-radius: 5px; color: #c62828;'>";
    echo "<h3>❌ Database Error</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<h2>💡 Quick Fix Options</h2>";
echo "<ul>";
echo "<li><strong>If Admin:</strong> Sessions should appear automatically</li>";
echo "<li><strong>If Unit User:</strong> Make sure proto_id is set in your session</li>";
echo "<li><strong>Emergency:</strong> <a href='run_cleanup.php'>Run System Cleanup</a> to fix proto_ids</li>";
echo "</ul>";

echo "<p><a href='admin/users_dashboard.php'>← Back to Dashboard</a> | <a href='api/schedule_api.php?action=get_calendar_events'>🔗 Test Calendar API</a></p>";
?>
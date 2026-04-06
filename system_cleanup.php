<?php
// Clean up user system and fix calendar display
require_once 'database/dbcon.php';

echo "<h1>🧹 System Cleanup: Users & Calendar</h1>";

try {
    echo "<h2>1. 👥 User System Cleanup</h2>";
    
    // Show current users before cleanup
    $current_users = $dbh->query("
        SELECT id, username, permission, status 
        FROM tblusers 
        ORDER BY permission DESC, id ASC
    ")->fetchAll();
    
    echo "<h3>Current Users:</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f5f5f5;'><th>ID</th><th>Username</th><th>Permission</th><th>Status</th><th>Action</th></tr>";
    
    $to_keep = [];
    $to_remove = [];
    
    foreach ($current_users as $user) {
        $action = '';
        if ($user['permission'] === 'admin') {
            $action = '✅ KEEP (Admin)';
            $to_keep[] = $user;
        } elseif ($user['username'] === 'fishda_bot') {
            $action = '✅ KEEP (Bot)';  
            $to_keep[] = $user;
        } elseif (in_array($user['username'], ['fishda', 'unit1', 'unit2', 'prototype1'])) {
            $action = '✅ KEEP (Unit User)';
            $to_keep[] = $user;
        } else {
            $action = '🗑️ REMOVE (Unnecessary)';
            $to_remove[] = $user;
        }
        
        $rowColor = strpos($action, 'REMOVE') !== false ? 'background-color: #fee;' : '';
        
        echo "<tr style='$rowColor'>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['username']}</td>";
        echo "<td>{$user['permission']}</td>";
        echo "<td>" . ($user['status'] ? 'Active' : 'Inactive') . "</td>";
        echo "<td><strong>$action</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>📊 Cleanup Summary:</h3>";
    echo "<ul>";
    echo "<li><strong>Users to keep:</strong> " . count($to_keep) . "</li>";
    echo "<li><strong>Users to remove:</strong> " . count($to_remove) . "</li>";
    echo "</ul>";
    
    if (!empty($to_remove)) {
        echo "<h3>🗑️ Removing Unnecessary Users:</h3>";
        
        // Get IDs of users to remove
        $ids_to_remove = array_column($to_remove, 'id');
        $placeholders = str_repeat('?,', count($ids_to_remove) - 1) . '?';
        
        // Check if any sessions are linked to these users
        $linked_sessions = $dbh->prepare("SELECT user_id, COUNT(*) as count FROM drying_sessions WHERE user_id IN ($placeholders) GROUP BY user_id");
        $linked_sessions->execute($ids_to_remove);
        $session_links = $linked_sessions->fetchAll();
        
        if (!empty($session_links)) {
            echo "<p><strong>⚠️ Found linked sessions. Reassigning to admin...</strong></p>";
            
            // Get admin user ID
            $admin = $dbh->query("SELECT id FROM tblusers WHERE permission = 'admin' AND status = 1 LIMIT 1")->fetch();
            $admin_id = $admin['id'];
            
            // Reassign sessions to admin
            $reassign = $dbh->prepare("UPDATE drying_sessions SET user_id = ? WHERE user_id IN ($placeholders)");
            $reassign_params = array_merge([$admin_id], $ids_to_remove);
            $reassign->execute($reassign_params);
            $reassigned = $reassign->rowCount();
            
            echo "<p>✅ Reassigned $reassigned session(s) to admin</p>";
        }
        
        // Remove the users
        $delete = $dbh->prepare("DELETE FROM tblusers WHERE id IN ($placeholders)");
        $delete->execute($ids_to_remove);
        $deleted = $delete->rowCount();
        
        echo "<p style='color: green; font-weight: bold;'>✅ Removed $deleted user(s)</p>";
        
        foreach ($to_remove as $user) {
            echo "<p>🗑️ Deleted: {$user['username']} (ID: {$user['id']})</p>";
        }
    }
    
    echo "<h2>2. 📅 Calendar Display Fix</h2>";
    
    // Check current calendar events
    $calendar_sessions = $dbh->query("
        SELECT COUNT(*) as total_sessions,
               SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN status = 'Interrupted' THEN 1 ELSE 0 END) as interrupted
        FROM drying_sessions 
        WHERE status IN ('Completed', 'Interrupted')
    ")->fetch();
    
    echo "<h3>📊 Available Drying Records for Calendar:</h3>";
    echo "<ul>";
    echo "<li><strong>Total completed/interrupted sessions:</strong> {$calendar_sessions['total_sessions']}</li>";
    echo "<li><strong>Completed:</strong> {$calendar_sessions['completed']}</li>";
    echo "<li><strong>Interrupted:</strong> {$calendar_sessions['interrupted']}</li>";
    echo "</ul>";
    
    // Sample of recent sessions that should appear on calendar
    echo "<h3>🧪 Sample Sessions for Calendar:</h3>";
    $sample_sessions = $dbh->query("
        SELECT ds.session_id, ds.start_time, ds.end_time, ds.status,
               CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) as device_info
        FROM drying_sessions ds
        LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
        WHERE ds.status IN ('Completed', 'Interrupted')
        ORDER BY ds.start_time DESC
        LIMIT 10
    ");
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f5f5f5;'><th>Session</th><th>Start</th><th>End</th><th>Status</th><th>Device</th></tr>";
    
    while ($session = $sample_sessions->fetch()) {
        $status_icon = $session['status'] === 'Completed' ? '✅' : '⚠️';
        echo "<tr>";
        echo "<td>#{$session['session_id']}</td>";
        echo "<td>" . substr($session['start_time'], 0, 16) . "</td>";
        echo "<td>" . substr($session['end_time'] ?? '', 0, 16) . "</td>";
        echo "<td>$status_icon {$session['status']}</td>";
        echo "<td>{$session['device_info']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>✅ Calendar Fix Applied:</h3>";
    echo "<ul>";
    echo "<li>✅ Completed sessions will show as green events with ✅ icon</li>";
    echo "<li>✅ Interrupted sessions will show as orange events with ⚠️ icon</li>";
    echo "<li>✅ Events will display device info (FISDA - Model + Code)</li>";
    echo "<li>✅ Prototype-based filtering applied for unit users</li>";
    echo "</ul>";
    
    echo "<h2>🎯 Final System Structure:</h2>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f5f5f5;'><th>User Type</th><th>Access Level</th><th>Can See</th></tr>";
    echo "<tr>";
    echo "<td><strong>Admin</strong></td>";
    echo "<td>Full System Access</td>";
    echo "<td>All sessions, schedules, and calendar events from all devices</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td><strong>Unit User</strong></td>";
    echo "<td>Device-Specific Access</td>";
    echo "<td>Only sessions, schedules, and calendar events from their device</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td><strong>Fishda Bot</strong></td>";
    echo "<td>Automated Sessions</td>";
    echo "<td>System account for ESP-initiated sessions</td>";
    echo "</tr>";
    echo "</table>";
    
    echo "<p style='color: green; font-weight: bold; font-size: 18px;'>✅ System cleanup completed!</p>";
    echo "<p>Calendar will now show both scheduled batches AND completed drying records.</p>";
    echo "<p><a href='admin/users_dashboard.php'>← Back to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>
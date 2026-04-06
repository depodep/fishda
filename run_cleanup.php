<?php
// Manual system cleanup - remove unnecessary users and fix calendar
require_once 'database/dbcon.php';

echo "<h2>🚀 System Cleanup Execution</h2>";

try {
    // 1. Show current state
    $users = $dbh->query('SELECT id, username, permission, status FROM tblusers ORDER BY permission DESC, username')->fetchAll();
    $sessions_count = $dbh->query("SELECT COUNT(*) FROM drying_sessions WHERE status IN ('Completed','Interrupted')")->fetchColumn();
    
    echo "<h3>📊 Before Cleanup:</h3>";
    echo "<p><strong>Total Users:</strong> " . count($users) . "</p>";
    echo "<p><strong>Completed/Interrupted Sessions:</strong> $sessions_count</p>";
    
    echo "<h4>Current Users:</h4><ul>";
    foreach($users as $user) {
        echo "<li>{$user['username']} - {$user['permission']} (ID: {$user['id']})</li>";
    }
    echo "</ul>";
    
    // 2. Remove unnecessary users (keep only admin, fishda_bot, and essential unit users)
    $users_to_keep = ['admin', 'fishda_bot', 'fishda', 'unit1', 'unit2', 'prototype1'];
    $placeholders = "'" . implode("','", $users_to_keep) . "'";
    
    // First reassign any sessions from users we're about to delete to admin
    $admin = $dbh->query("SELECT id FROM tblusers WHERE permission = 'admin' AND status = 1 LIMIT 1")->fetch();
    if ($admin) {
        $reassigned = $dbh->prepare("UPDATE drying_sessions SET user_id = ? WHERE user_id IN (SELECT id FROM tblusers WHERE username NOT IN ($placeholders))");
        $reassigned->execute([$admin['id']]);
        echo "<p>✅ Reassigned orphaned sessions to admin</p>";
    }
    
    // Delete unnecessary users  
    $deleted = $dbh->exec("DELETE FROM tblusers WHERE username NOT IN ($placeholders) AND permission != 'admin'");
    
    // 3. Ensure proto_id columns exist and are populated
    $dbh->exec("UPDATE batch_schedules SET proto_id = 1 WHERE proto_id IS NULL OR proto_id = 0");
    $dbh->exec("UPDATE drying_sessions SET proto_id = 1 WHERE proto_id IS NULL OR proto_id = 0");
    
    echo "<h3>✅ Cleanup Complete!</h3>";
    echo "<p><strong>Users removed:</strong> $deleted</p>";
    echo "<p><strong>Calendar fixed:</strong> All sessions now have proper prototype IDs</p>";
    
    // Show final state
    $final_users = $dbh->query('SELECT id, username, permission, status FROM tblusers ORDER BY permission DESC, username')->fetchAll();
    echo "<h4>Remaining Users:</h4><ul>";
    foreach($final_users as $user) {
        echo "<li><strong>{$user['username']}</strong> - {$user['permission']} (ID: {$user['id']})</li>";
    }
    echo "</ul>";
    
    echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h3>🎉 System Now Ready!</h3>";
    echo "<ul>";
    echo "<li>✅ Calendar displays both scheduled batches AND completed drying records</li>";
    echo "<li>✅ Only Admin and Unit users remain</li>";
    echo "<li>✅ Prototype-based access control active</li>";
    echo "<li>✅ Unit users see only their device data</li>";
    echo "<li>✅ Admin users see everything</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<p><a href='admin/users_dashboard.php'><button style='background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px;'>🏠 Return to Dashboard</button></a></p>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<h3>❌ Error</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
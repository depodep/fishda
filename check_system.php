<?php
require_once 'database/dbcon.php';

echo "=== CURRENT SYSTEM STATE ===\n";
echo "Users:\n";
$users = $dbh->query('SELECT id, username, permission, status FROM tblusers ORDER BY permission DESC, username')->fetchAll();
foreach($users as $user) {
    echo "- {$user['username']} (ID: {$user['id']}, Permission: {$user['permission']}, Status: {$user['status']})\n";
}

echo "\nCompleted/Interrupted Sessions: " . $dbh->query("SELECT COUNT(*) FROM drying_sessions WHERE status IN ('Completed','Interrupted')")->fetchColumn() . "\n";

echo "\n=== CLEANUP ACTION ===\n";
$keep_users = ['admin', 'fishda_bot', 'fishda', 'unit1', 'unit2', 'prototype1'];
$remove_count = 0;

foreach($users as $user) {
    if ($user['permission'] === 'admin') {
        echo "✅ KEEP: {$user['username']} (Admin)\n";
    } elseif (in_array($user['username'], $keep_users)) {
        echo "✅ KEEP: {$user['username']} (Unit/Bot)\n";
    } else {
        echo "🗑️ REMOVE: {$user['username']}\n";
        $remove_count++;
    }
}

echo "\nUsers to remove: $remove_count\n";

if ($remove_count > 0) {
    echo "\n❗ Execute emergency_system_fix.php to clean up!\n";
} else {
    echo "\n✅ System is already clean!\n";
}
?>
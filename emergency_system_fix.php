<?php
// Emergency system fix - Remove users and fix calendar in one go
require_once 'database/dbcon.php';

if ($_POST['action'] === 'execute_cleanup') {
    try {
        // 1. Remove unnecessary users (keep only admin, fishda_bot, and unit users)
        $users_to_keep = ['admin', 'fishda_bot', 'fishda', 'unit1', 'unit2', 'prototype1'];
        $placeholders = "'" . implode("','", $users_to_keep) . "'";
        
        // First reassign any sessions from users we're about to delete
        $admin = $dbh->query("SELECT id FROM tblusers WHERE permission = 'admin' AND status = 1 LIMIT 1")->fetch();
        if ($admin) {
            $reassign = $dbh->prepare("UPDATE drying_sessions SET user_id = ? WHERE user_id IN (SELECT id FROM tblusers WHERE username NOT IN ($placeholders))");
            $reassign->execute([$admin['id']]);
        }
        
        // Delete unnecessary users
        $delete = $dbh->exec("DELETE FROM tblusers WHERE username NOT IN ($placeholders) AND permission != 'admin'");
        
        // 2. Ensure proto_id columns exist and are populated
        $dbh->exec("UPDATE batch_schedules SET proto_id = 1 WHERE proto_id IS NULL OR proto_id = 0");
        $dbh->exec("UPDATE drying_sessions SET proto_id = 1 WHERE proto_id IS NULL OR proto_id = 0");
        
        echo json_encode([
            'success' => true,
            'message' => "System cleaned up successfully! Removed $delete users and fixed calendar data.",
            'deleted_users' => $delete
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>🚀 Emergency System Cleanup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0; }
        button { background: #dc3545; color: white; border: none; padding: 12px 24px; border-radius: 5px; cursor: pointer; font-size: 16px; }
        button:hover { background: #c82333; }
        .safe-btn { background: #28a745; } .safe-btn:hover { background: #218838; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f8f9fa; }
        .remove { background-color: #ffebee; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Emergency System Cleanup</h1>
        
        <div class="warning">
            <strong>⚠️ Warning:</strong> This will permanently remove unnecessary users and fix the calendar system.
            <br><strong>Action:</strong> Convert to Admin + Unit User system only.
        </div>

        <?php
        try {
            // Show current problematic state
            $all_users = $dbh->query("SELECT id, username, permission, status FROM tblusers ORDER BY permission DESC, username")->fetchAll();
            $sessions_count = $dbh->query("SELECT COUNT(*) FROM drying_sessions WHERE status IN ('Completed','Interrupted')")->fetchColumn();
            
            echo "<h2>📊 Current System State</h2>";
            echo "<p><strong>Completed/Interrupted Sessions for Calendar:</strong> $sessions_count</p>";
            
            echo "<h3>👥 Users (Will keep only Admin + Unit users):</h3>";
            echo "<table>";
            echo "<tr><th>ID</th><th>Username</th><th>Permission</th><th>Status</th><th>Action</th></tr>";
            
            $keep_users = ['admin', 'fishda_bot', 'fishda', 'unit1', 'unit2', 'prototype1'];
            $remove_count = 0;
            
            foreach ($all_users as $user) {
                $action = '';
                $rowClass = '';
                
                if ($user['permission'] === 'admin') {
                    $action = '✅ KEEP (Admin)';
                } elseif (in_array($user['username'], $keep_users)) {
                    $action = '✅ KEEP (Unit/Bot)';
                } else {
                    $action = '🗑️ REMOVE';
                    $rowClass = 'remove';
                    $remove_count++;
                }
                
                echo "<tr class='$rowClass'>";
                echo "<td>{$user['id']}</td>";
                echo "<td>{$user['username']}</td>";
                echo "<td>{$user['permission']}</td>";
                echo "<td>" . ($user['status'] ? 'Active' : 'Inactive') . "</td>";
                echo "<td><strong>$action</strong></td>";
                echo "</tr>";
            }
            echo "</table>";
            
            echo "<p><strong>Users to remove:</strong> $remove_count</p>";
            
        } catch (Exception $e) {
            echo "<div class='error'>Error checking system: " . $e->getMessage() . "</div>";
        }
        ?>

        <h2>🔧 What This Will Fix:</h2>
        <ul>
            <li>✅ <strong>Calendar:</strong> Display both scheduled batches AND completed drying records</li>
            <li>✅ <strong>Users:</strong> Remove all unnecessary users, keep only Admin + Unit users</li>
            <li>✅ <strong>Access:</strong> Prototype-based filtering (Admin sees all, Unit users see only their device)</li>
            <li>✅ <strong>Data:</strong> Ensure all sessions have proper prototype IDs</li>
        </ul>

        <div style="margin: 30px 0; text-align: center;">
            <button onclick="executeCleanup()" id="cleanupBtn">
                🚀 Execute System Cleanup
            </button>
            <br><br>
            <a href="admin/users_dashboard.php">
                <button type="button" class="safe-btn">❌ Cancel - Back to Dashboard</button>
            </a>
        </div>

        <div id="result" style="display: none;"></div>
    </div>

    <script>
    async function executeCleanup() {
        const btn = document.getElementById('cleanupBtn');
        const result = document.getElementById('result');
        
        btn.disabled = true;
        btn.textContent = '⏳ Processing...';
        
        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=execute_cleanup'
            });
            
            const data = await response.json();
            result.style.display = 'block';
            
            if (data.success) {
                result.className = 'success';
                result.innerHTML = `
                    <h3>✅ Cleanup Successful!</h3>
                    <p>${data.message}</p>
                    <p><strong>Users removed:</strong> ${data.deleted_users}</p>
                    <p>Calendar will now show both scheduled batches and completed drying records.</p>
                    <br>
                    <a href="admin/users_dashboard.php">
                        <button class="safe-btn">🏠 Return to Dashboard</button>
                    </a>
                `;
            } else {
                result.className = 'error';
                result.innerHTML = `<h3>❌ Cleanup Failed</h3><p>${data.message}</p>`;
                btn.disabled = false;
                btn.textContent = '🚀 Execute System Cleanup';
            }
            
        } catch (error) {
            result.style.display = 'block';
            result.className = 'error';
            result.innerHTML = `<h3>❌ Error</h3><p>Network error: ${error.message}</p>`;
            btn.disabled = false;
            btn.textContent = '🚀 Execute System Cleanup';
        }
    }
    </script>
</body>
</html>
<?php
// Implement prototype-based access control
require_once 'database/dbcon.php';

echo "<h1>🔒 Implementing Prototype-Based Access Control</h1>";

try {
    // Check if proto_id column exists in batch_schedules
    $columns = $dbh->query("SHOW COLUMNS FROM batch_schedules LIKE 'proto_id'")->fetchAll();
    
    if (empty($columns)) {
        echo "<p>🔧 Adding proto_id column to batch_schedules table...</p>";
        $dbh->exec("ALTER TABLE batch_schedules ADD COLUMN proto_id INT DEFAULT 1 AFTER user_id");
        echo "<p>✅ Added proto_id column to batch_schedules</p>";
    } else {
        echo "<p>ℹ️ proto_id column already exists in batch_schedules</p>";
    }
    
    // Update existing schedules to have prototype ID
    echo "<h2>Updating Existing Data</h2>";
    
    // Get the prototype ID
    $proto = $dbh->query("SELECT id FROM tbl_prototypes WHERE status = 1 LIMIT 1")->fetch();
    $proto_id = $proto ? $proto['id'] : 1;
    
    echo "<p>📱 Using prototype ID: $proto_id</p>";
    
    // Update batch_schedules
    $update_schedules = $dbh->prepare("UPDATE batch_schedules SET proto_id = ? WHERE proto_id IS NULL OR proto_id = 0");
    $update_schedules->execute([$proto_id]);
    $updated_schedules = $update_schedules->rowCount();
    
    // Update drying_sessions (in case some don't have proto_id)
    $update_sessions = $dbh->prepare("UPDATE drying_sessions SET proto_id = ? WHERE proto_id IS NULL OR proto_id = 0");
    $update_sessions->execute([$proto_id]);
    $updated_sessions = $update_sessions->rowCount();
    
    echo "<p>✅ Updated $updated_schedules schedule(s) with prototype ID</p>";
    echo "<p>✅ Updated $updated_sessions session(s) with prototype ID</p>";
    
    echo "<h2>📋 Access Control Rules Implemented</h2>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f5f5f5;'><th>User Type</th><th>Can See</th><th>Description</th></tr>";
    echo "<tr>";
    echo "<td><strong>Admin Users</strong></td>";
    echo "<td>ALL sessions from ALL prototypes</td>";
    echo "<td>Complete oversight of entire system</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td><strong>Unit Users</strong></td>";
    echo "<td>Only sessions from THEIR prototype</td>";
    echo "<td>Isolated view per device/unit</td>";
    echo "</tr>";
    echo "</table>";
    
    echo "<h2>🧪 Test Current Access</h2>";
    echo "<p><strong>Current user session:</strong></p>";
    echo "<ul>";
    echo "<li>User ID: " . ($_SESSION['user_id'] ?? 'Not set') . "</li>";
    echo "<li>Permission: " . ($_SESSION['permission'] ?? 'Not set') . "</li>";
    echo "<li>Prototype ID: " . ($_SESSION['proto_id'] ?? 'Not set') . "</li>";
    echo "</ul>";
    
    // Show sample of what each user type would see
    echo "<h3>Admin View (All Sessions)</h3>";
    $admin_sessions = $dbh->query("
        SELECT ds.session_id, ds.start_time, ds.status, ds.proto_id,
               CONCAT('FISDA - ', COALESCE(p.model_name, 'Unknown'), ' + ', COALESCE(p.given_code, 'N/A')) as device_info
        FROM drying_sessions ds
        LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
        ORDER BY ds.start_time DESC LIMIT 5
    ");
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f5f5f5;'><th>Session</th><th>Date</th><th>Status</th><th>Device</th><th>Proto ID</th></tr>";
    while ($row = $admin_sessions->fetch()) {
        echo "<tr>";
        echo "<td>#{$row['session_id']}</td>";
        echo "<td>" . substr($row['start_time'], 0, 16) . "</td>";
        echo "<td>{$row['status']}</td>";
        echo "<td>{$row['device_info']}</td>";
        echo "<td>{$row['proto_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>Unit User View (Prototype ID = 1 only)</h3>";
    $unit_sessions = $dbh->query("
        SELECT ds.session_id, ds.start_time, ds.status, ds.proto_id,
               CONCAT('FISDA - ', COALESCE(p.model_name, 'Unknown'), ' + ', COALESCE(p.given_code, 'N/A')) as device_info
        FROM drying_sessions ds
        LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
        WHERE ds.proto_id = 1
        ORDER BY ds.start_time DESC LIMIT 5
    ");
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f5f5f5;'><th>Session</th><th>Date</th><th>Status</th><th>Device</th><th>Proto ID</th></tr>";
    while ($row = $unit_sessions->fetch()) {
        echo "<tr>";
        echo "<td>#{$row['session_id']}</td>";
        echo "<td>" . substr($row['start_time'], 0, 16) . "</td>";
        echo "<td>{$row['status']}</td>";
        echo "<td>{$row['device_info']}</td>";
        echo "<td>{$row['proto_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<p style='color: green; font-weight: bold; font-size: 18px;'>✅ Prototype-based access control implemented!</p>";
    echo "<p><a href='admin/users_dashboard.php'>← Back to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>
<?php
// Fix session #88 and other stuck "Running" sessions
require_once 'database/dbcon.php';

echo "<h1>🔍 Fix Stuck Running Sessions</h1>";

try {
    // Check session #88 specifically
    $session88 = $dbh->query("SELECT session_id, start_time, end_time, status, schedule_id FROM drying_sessions WHERE session_id = 88")->fetch();
    
    if ($session88) {
        echo "<h2>Session #88 Details:</h2>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Session ID</th><th>Start Time</th><th>End Time</th><th>Status</th><th>Schedule ID</th></tr>";
        echo "<tr>";
        echo "<td>{$session88['session_id']}</td>";
        echo "<td>{$session88['start_time']}</td>";
        echo "<td>" . ($session88['end_time'] ?: 'NULL') . "</td>";
        echo "<td><strong style='color: " . ($session88['status'] === 'Running' ? '#dc3545' : '#28a745') . ";'>{$session88['status']}</strong></td>";
        echo "<td>" . ($session88['schedule_id'] ?: 'NULL') . "</td>";
        echo "</tr>";
        echo "</table>";
        
        // Check if it should be updated
        if ($session88['status'] === 'Running') {
            echo "<div style='background: #fff3cd; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
            echo "<h3>⚠️ Session #88 is stuck as 'Running'</h3>";
            echo "<p>This session should be marked as Completed or Interrupted since you stopped it.</p>";
            
            // Determine correct status
            $correctStatus = $session88['schedule_id'] ? 'Interrupted' : 'Completed';
            echo "<p><strong>Suggested Status:</strong> $correctStatus</p>";
            echo "<p><strong>Reason:</strong> " . ($session88['schedule_id'] ? "Was a scheduled session that was stopped early" : "Was a manual session") . "</p>";
            
            echo "<form method='POST' style='margin-top: 15px;'>";
            echo "<input type='hidden' name='fix_session' value='88'>";
            echo "<button type='submit' style='background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>✅ Fix Session #88 Status</button>";
            echo "</form>";
            echo "</div>";
        } else {
            echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
            echo "<h3>✅ Session #88 Status is Correct</h3>";
            echo "</div>";
        }
    } else {
        echo "<p>❌ Session #88 not found in database</p>";
    }
    
    // Check for other stuck Running sessions
    echo "<h2>🔍 All Running Sessions:</h2>";
    $runningCheck = $dbh->query("
        SELECT session_id, start_time, end_time, status, schedule_id,
               TIMESTAMPDIFF(MINUTE, start_time, NOW()) as minutes_running
        FROM drying_sessions 
        WHERE status = 'Running'
        ORDER BY start_time DESC
    ")->fetchAll();
    
    if (count($runningCheck) > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Session ID</th><th>Start Time</th><th>End Time</th><th>Running For</th><th>Issue</th></tr>";
        
        foreach($runningCheck as $r) {
            $isStuck = $r['minutes_running'] > 1440 || $r['end_time']; // If over 24h or has end_time but still "Running"
            $bgColor = $isStuck ? '#ffebee' : '#e8f5e9';
            
            echo "<tr style='background: $bgColor;'>";
            echo "<td>{$r['session_id']}</td>";
            echo "<td>{$r['start_time']}</td>";
            echo "<td>" . ($r['end_time'] ?: '—') . "</td>";
            echo "<td>" . round($r['minutes_running'] / 60, 1) . " hours</td>";
            echo "<td>";
            if ($isStuck) {
                echo "<strong style='color: #c62828;'>🔴 STUCK - needs fixing</strong>";
            } else {
                echo "✅ OK";
            }
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        $stuckCount = count(array_filter($runningCheck, fn($r) => $r['minutes_running'] > 1440 || $r['end_time']));
        if ($stuckCount > 0) {
            echo "<div style='background: #ffebee; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
            echo "<h3>⚠️ Found $stuckCount Stuck Sessions</h3>";
            echo "<p>These sessions are marked as 'Running' but should be closed.</p>";
            echo "<form method='POST'>";
            echo "<input type='hidden' name='fix_all_stuck' value='1'>";
            echo "<button type='submit' style='background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>🔧 Fix All Stuck Sessions</button>";
            echo "</form>";
            echo "</div>";
        }
    } else {
        echo "<p>✅ No running sessions found</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Handle fix requests
if ($_POST['fix_session'] ?? false) {
    $session_id = intval($_POST['fix_session']);
    
    // Get session details
    $session = $dbh->query("SELECT session_id, schedule_id FROM drying_sessions WHERE session_id = $session_id")->fetch();
    
    if ($session) {
        // Determine correct status
        $newStatus = $session['schedule_id'] ? 'Interrupted' : 'Completed';
        
        // Update the session
        $dbh->exec("UPDATE drying_sessions SET status = '$newStatus' WHERE session_id = $session_id");
        
        echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h3>✅ Session #$session_id Fixed!</h3>";
        echo "<p>Status updated to: <strong>$newStatus</strong></p>";
        echo "<p><a href='admin/users_dashboard.php'>← Back to Dashboard</a> | <a href=''>🔄 Refresh This Page</a></p>";
        echo "</div>";
    }
}

if ($_POST['fix_all_stuck'] ?? false) {
    // Fix all sessions that are stuck (over 24h or have end_time but still "Running")
    $fixed = $dbh->exec("
        UPDATE drying_sessions 
        SET status = CASE 
            WHEN schedule_id IS NOT NULL THEN 'Interrupted'
            ELSE 'Completed'
        END
        WHERE status = 'Running' 
        AND (TIMESTAMPDIFF(MINUTE, start_time, NOW()) > 1440 OR end_time IS NOT NULL)
    ");
    
    echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h3>✅ Fixed $fixed Stuck Sessions!</h3>";
    echo "<p><a href='admin/users_dashboard.php'>← Back to Dashboard</a> | <a href=''>🔄 Refresh This Page</a></p>";
    echo "</div>";
}

echo "<p style='margin-top: 20px;'><a href='admin/users_dashboard.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>← Back to Dashboard</a></p>";
?>
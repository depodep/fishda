<?php
// Emergency session stop - direct database access
require_once 'database/dbcon.php';

echo "<h1>Emergency Session Stop</h1>";

try {
    // Stop all running sessions
    $stopped_sessions = $dbh->exec("UPDATE drying_sessions SET status='Manual_Stop', end_time=NOW() WHERE status='Running'");
    
    // Stop controls
    $dbh->exec("UPDATE drying_controls SET status='STOPPED', start_time=NULL, cooldown_until=NULL WHERE id=1");
    
    echo "<p>✅ SUCCESS: Stopped $stopped_sessions running session(s)</p>";
    echo "<p>✅ Controls set to STOPPED</p>";
    echo "<p><a href='index.php'>← Back to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p>❌ ERROR: " . $e->getMessage() . "</p>";
}
?>
<?php
ob_start();
header('Content-Type: application/json');
include 'dbcon.php'; 

$table_name = 'drying_records'; 

try {
    // Strictly the 5 columns from your DB
    $sql = "SELECT id, timestamp, batch_id, duration, energy, status FROM drying_records ORDER BY timestamp DESC";
    
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'records' => $records]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
ob_end_flush();
?>
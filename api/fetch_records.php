<?php
ob_start();
header('Content-Type: application/json');
include '../database/dbcon.php';

try {
    $sql = "SELECT
                ds.session_id AS id,
                COALESCE(ds.end_time, ds.start_time) AS timestamp,
                ds.proto_id AS batch_id,
                TIMEDIFF(COALESCE(ds.end_time, NOW()), ds.start_time) AS duration,
                0 AS energy,
                ds.status
            FROM drying_sessions ds
            WHERE ds.status <> 'Running'
            ORDER BY COALESCE(ds.end_time, ds.start_time) DESC";
    
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'records' => $records]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
ob_end_flush();
?>
<?php
header('Content-Type: application/json');
include 'dbcon.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batchId  = $_POST['batchId']  ?? 'Manual Batch';
    $duration = $_POST['duration'] ?? '00:00:00';
    $energy   = $_POST['energy']   ?? 0;
    $temp     = $_POST['t']        ?? 0;
    $hum      = $_POST['h']        ?? 0;
    $status   = $_POST['status']   ?? 'Completed';

    try {
        $sql = "INSERT INTO drying_records (batch_id, duration, energy, temp_avg, hum_avg, status)
                VALUES (:batchId, :duration, :energy, :t, :h, :status)";
        $stmt = $dbh->prepare($sql);
        $stmt->bindParam(':batchId',   $batchId);
        $stmt->bindParam(':duration',  $duration);
        $stmt->bindParam(':energy',    $energy);
        $stmt->bindParam(':t',         $temp);
        $stmt->bindParam(':h',         $hum);
        $stmt->bindParam(':status',    $status);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Record saved.', 'id' => $dbh->lastInsertId()]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Insert failed.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'POST request required.']);
}
?>
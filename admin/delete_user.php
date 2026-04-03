<?php
session_start();
include('../database/dbcon.php');

// Security Check: Only allow admins to delete
if (!isset($_SESSION['username']) || $_SESSION['permission'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

if (isset($_POST['id'])) {
    $user_id = $_POST['id'];

    try {
        // Prepare deletion query
        $sql = "DELETE FROM tblusers WHERE id = :id";
        $query = $dbh->prepare($sql);
        $query->bindParam(':id', $user_id, PDO::PARAM_INT);
        
        if ($query->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'User deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete user']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No ID provided']);
}
?>
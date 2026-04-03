<?php
// delete_record.php

// Include the database connection file
require_once 'dbcon.php';

header('Content-Type: application/json');

// Check if the request method is POST and the record ID is set
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    
    // Sanitize and validate the record ID
    $recordId = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    
    if ($recordId === false) {
        http_response_code(400); // Bad Request
        echo json_encode(['status' => 'error', 'message' => 'Invalid record ID.']);
        exit;
    }

    try {
        // Prepare the SQL statement for deleting the record
        $stmt = $dbh->prepare("DELETE FROM drying_records WHERE id = :id");
        
        // Bind the parameter
        $stmt->bindParam(':id', $recordId);
        
        // Execute the statement
        $stmt->execute();

        // Check if a record was deleted
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Record deleted successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Record not found or already deleted.']);
        }

    } catch (PDOException $e) {
        // Log and return a generic error message
        error_log("Error deleting record: " . $e->getMessage(), 3, 'error.log');
        http_response_code(500); // Internal Server Error
        echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
    }

} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method or missing data.']);
}
?>
<?php
// CRITICAL FIX: Output buffering and error suppression for clean JSON response
// This prevents stray characters or PHP warnings from corrupting the JSON for your AJAX call.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_NONE);
if (ob_get_level()) {
    ob_clean(); // Discard any output buffer content (BOM, errors, etc.)
}

// Set the content type header to ensure the response is treated as JSON
header('Content-Type: application/json');

// --- Database Connection ---
// ⚠️ Ensure 'dbcon.php' successfully defines the PDO object as $dbh.
include '../database/dbcon.php';

try {
    // Define the SQL query to select user data. 
    // NOTE: We generally avoid selecting the 'password' column here for security, 
    // as it's not needed for display. I'm removing it for better practice.
    $sql = "SELECT 
                id, 
                username, 
                permission 
            FROM tblusers 
            ORDER BY id ASC";
    
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Send a successful JSON response with the fetched data
    echo json_encode(['status' => 'success', 'users' => $users]);

} catch (PDOException $e) {
    // Catch database-specific errors (e.g., table not found, bad column name)
    error_log('DB Query Error in fetch_users.php: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error', 
        'message' => 'Failed to retrieve users. Check database connection and table names. Error: ' . $e->getMessage()
    ]);

} catch (Throwable $e) {
    // Catch any other unexpected server errors
    error_log('Unexpected Error in fetch_users.php: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error', 
        'message' => 'An unexpected server error occurred.'
    ]);
}
?>
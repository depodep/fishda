<?php
// Include the database connection file
include('../database/dbcon.php');

// If the previous line didn't die(), the connection was successful.
// Display a success message.
echo "<h1>✅ Database connection successful!</h1>";
echo "<p>The connection to the 'loginsystem_db' database was established without any errors.</p>";
echo "<p>You can now delete this file and continue with your project.</p>";

// You can add a simple query to test if the database is accessible
try {
    $stmt = $dbh->query("SELECT 1");
    if ($stmt) {
        echo "<p>A basic query was also successful.</p>";
    } else {
        echo "<p>Basic query failed.</p>";
    }
} catch (PDOException $e) {
    echo "<p>A basic query failed: " . $e->getMessage() . "</p>";
}
?>
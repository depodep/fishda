<?php
// Database credentials
$host = 'localhost';
$user = 'root'; // Change this to your database username
$password = ''; // Change this to your database password
$database = 'solar_dashboard'; // This should match the database name you created

// Create a new mysqli connection
$con = new mysqli($host, $user, $password, $database);

// Check for connection errors
if ($con->connect_error) {
    die("Connection failed: " . $con->connect_error);
}
?>

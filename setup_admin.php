<?php
/**
 * setup_admin.php
 * ----------------------------------------------------
 * Utility script to create the initial admin user.
 * RUN THIS ONLY ONCE after setting up the database schema.
 * After running, DELETE this file for security.
 * ----------------------------------------------------
 */

// 1. Include the database connection file
require_once 'dbcon.php';

// --- CONFIGURATION: CHANGE THESE CREDENTIALS IMMEDIATELY ---
$admin_username = 'admin';
$admin_password = 'fishdrying'; // <--- CHANGE THIS!
// -----------------------------------------------------------

echo "<h1>Fish Drying System Admin Setup</h1>";
echo "Attempting to create user: <strong>" . htmlspecialchars($admin_username) . "</strong> with role <strong>admin</strong>...<br>";

try {
    // 2. Hash the password for secure storage
    $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);

    // 3. Check if the user already exists
    $sql_check = "SELECT id FROM tblusers WHERE username = :username";
    $query_check = $dbh->prepare($sql_check);
    $query_check->bindParam(':username', $admin_username, PDO::PARAM_STR);
    $query_check->execute();

    if ($query_check->rowCount() > 0) {
        echo "<p style='color: orange; font-weight: bold;'>&#9888; User '{$admin_username}' already exists. Setup aborted.</p>";
        echo "<p>If you need to change the password, please do so manually or via the 'forgot password' function in your final application.</p>";
        exit;
    }

    // 4. Insert the new admin user
    $sql_insert = "INSERT INTO tblusers (username, password, permission, status) 
                   VALUES (:username, :password, :permission, :status)";
    
    $query_insert = $dbh->prepare($sql_insert);
    
    // Bind parameters
    $permission = 'admin';
    $status = 1;
    
    $query_insert->bindParam(':username', $admin_username, PDO::PARAM_STR);
    $query_insert->bindParam(':password', $hashed_password, PDO::PARAM_STR);
    $query_insert->bindParam(':permission', $permission, PDO::PARAM_STR);
    $query_insert->bindParam(':status', $status, PDO::PARAM_INT);
    
    $query_insert->execute();

    echo "<p style='color: green; font-weight: bold;'>&#10004; Admin user '{$admin_username}' successfully created!</p>";
    echo "<p>Password used: <strong>{$admin_password}</strong> (Remember to change this default!)</p>";
    echo "<p style='color: red; font-weight: bold;'>&#9940; SECURITY WARNING: Please delete this file (setup_admin.php) immediately after running it successfully.</p>";

} catch (PDOException $e) {
    echo "<p style='color: red; font-weight: bold;'>&#10060; Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Please ensure your database connection settings in <code>dbcon.php</code> are correct and the <code>tblusers</code> table exists.</p>";
} catch (Exception $e) {
    echo "<p style='color: red; font-weight: bold;'>&#10060; General Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

?>

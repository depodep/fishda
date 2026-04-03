<?php
include '../database/dbcon.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Username and password are required.']);
        exit;
    }

    try {
        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Prepare the SQL statement
        $sql = "INSERT INTO tblusers (username, password, permission, status) VALUES (:username, :password, 1, 1)";
        $stmt = $dbh->prepare($sql);
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'User added successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation (e.g., duplicate username)
            echo json_encode(['status' => 'error', 'message' => 'Username already exists.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>
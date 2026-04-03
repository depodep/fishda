<?php
// ============================================================
//  dbcon.php — Database Connection
//  Used by all PHP API files
// ============================================================
$host   = "localhost";
$user   = "root";
$pass   = "";           // <-- change if your MySQL has a password
$dbname = "fish_drying";

try {
    $dbh = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass
    );
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Alias — some files use $pdo, some use $dbh
    $pdo = $dbh;

} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode([
        "status"  => "error",
        "message" => "Database Connection Failed: " . $e->getMessage()
    ]));
}
?>
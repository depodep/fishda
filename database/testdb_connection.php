<?php
// Include the database connection file.
// This is the file that contains your PDO connection logic.
include('dbcon.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #f0f2f5;
        }
        .container {
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 500px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        h2 {
            margin-top: 0;
        }
        p {
            font-size: 1.1em;
        }
    </style>
</head>
<body>
    <div class="container <?php echo isset($dbh) && $dbh ? 'success' : 'error'; ?>">
        <?php if (isset($dbh) && $dbh): ?>
            <h2>Success!</h2>
            <p>Database connection was successful. You are good to go!</p>
        <?php else: ?>
            <h2>Connection Failed!</h2>
            <p>There was an error connecting to the database. Please check your credentials in `dbcon.php`.</p>
        <?php endif; ?>
    </div>
</body>
</html>

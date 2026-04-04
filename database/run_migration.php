<?php
/**
 * Database Migration Runner for Dual Hardware Support
 * Run this file to apply dual hardware schema updates
 */

// Database connection
try {
    $pdo = new PDO('mysql:host=localhost;dbname=fish_drying', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to database\n";
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Read migration file
$migrationFile = 'migrate_dual_hardware.sql';
if (!file_exists($migrationFile)) {
    echo "✗ Migration file not found: $migrationFile\n";
    exit(1);
}

$sql = file_get_contents($migrationFile);
echo "✓ Migration file loaded\n";

// Execute migration statements
$statements = array_filter(array_map('trim', explode(';', $sql)));
$executed = 0;
$errors = 0;

foreach ($statements as $statement) {
    if (!empty($statement) && !str_starts_with($statement, '--')) {
        try {
            // Show what we're executing
            $preview = substr(preg_replace('/\s+/', ' ', $statement), 0, 60);
            echo "  Executing: $preview...\n";
            
            $pdo->exec($statement);
            $executed++;
        } catch (Exception $e) {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}

echo "\n";
echo "Migration Summary:\n";
echo "- Statements executed: $executed\n";
echo "- Errors: $errors\n";

if ($errors === 0) {
    echo "✓ Database migration completed successfully!\n";
} else {
    echo "⚠ Migration completed with $errors errors\n";
}

// Verify key tables
echo "\nVerification:\n";
try {
    // Check if new columns exist
    $result = $pdo->query("SHOW COLUMNS FROM drying_logs LIKE 'fan1_state'");
    if ($result->rowCount() > 0) {
        echo "✓ drying_logs.fan1_state column added\n";
    } else {
        echo "✗ drying_logs.fan1_state column missing\n";
    }
    
    $result = $pdo->query("SHOW TABLES LIKE 'live_sensor_cache'");
    if ($result->rowCount() > 0) {
        echo "✓ live_sensor_cache table exists\n";
    } else {
        echo "✗ live_sensor_cache table missing\n";
    }
    
    $result = $pdo->query("SHOW COLUMNS FROM batch_schedules LIKE 'duration_hours'");
    if ($result->rowCount() > 0) {
        echo "✓ batch_schedules.duration_hours column added\n";
    } else {
        echo "✗ batch_schedules.duration_hours column missing\n";
    }
    
} catch (Exception $e) {
    echo "✗ Verification failed: " . $e->getMessage() . "\n";
}

echo "\nMigration process complete.\n";
?>
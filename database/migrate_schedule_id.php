<?php
// ============================================================
//  DATABASE MIGRATION - Add schedule_id and duration to enable dynamic scheduling
//  Run this once to update the database schema for automatic session control
// ============================================================

include('../database/dbcon.php');

try {
    echo "🔄 Starting Dynamic Scheduling Migration...\n\n";

    // 1. Add schedule_id column to drying_sessions if not exists
    $checkColumn = $dbh->query("SHOW COLUMNS FROM drying_sessions LIKE 'schedule_id'")->fetch();
    
    if (!$checkColumn) {
        $dbh->exec("
            ALTER TABLE drying_sessions 
            ADD COLUMN schedule_id INT(11) NULL DEFAULT NULL AFTER status,
            ADD INDEX idx_schedule_id (schedule_id)
        ");
        echo "✅ Added schedule_id column to drying_sessions table\n";
    } else {
        echo "ℹ️  Column schedule_id already exists in drying_sessions table\n";
    }

    // 2. Add duration column to batch_schedules if not exists
    $checkDuration = $dbh->query("SHOW COLUMNS FROM batch_schedules LIKE 'duration_hours'")->fetch();
    
    if (!$checkDuration) {
        $dbh->exec("
            ALTER TABLE batch_schedules 
            ADD COLUMN duration_hours DECIMAL(4,2) NOT NULL DEFAULT 2.00 AFTER set_humidity,
            ADD COLUMN auto_started TINYINT(1) DEFAULT 0 AFTER status,
            ADD COLUMN last_checked DATETIME NULL DEFAULT NULL AFTER auto_started
        ");
        echo "✅ Added duration_hours, auto_started, and last_checked columns to batch_schedules table\n";
    } else {
        echo "ℹ️  Duration columns already exist in batch_schedules table\n";
    }

    // 3. Add foreign key constraint if not exists
    $checkConstraint = $dbh->query("
        SELECT COUNT(*) as count 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_NAME = 'drying_sessions' 
        AND CONSTRAINT_NAME LIKE '%schedule%'
    ")->fetch();
    
    if ($checkConstraint['count'] == 0) {
        $dbh->exec("
            ALTER TABLE drying_sessions 
            ADD CONSTRAINT fk_session_schedule 
            FOREIGN KEY (schedule_id) REFERENCES batch_schedules(id) 
            ON DELETE SET NULL
        ");
        echo "✅ Added foreign key constraint for schedule_id\n";
    } else {
        echo "ℹ️  Foreign key constraint already exists\n";
    }

    // 4. Update existing schedules with default 2-hour duration
    $dbh->exec("UPDATE batch_schedules SET duration_hours = 2.00 WHERE duration_hours = 0 OR duration_hours IS NULL");
    echo "✅ Updated existing schedules with default 2-hour duration\n";

    echo "\n✅ Dynamic Scheduling Migration completed successfully!\n\n";
    echo "📋 New Features Added:\n";
    echo "   • Sessions automatically start when schedule time arrives\n";
    echo "   • Sessions automatically stop when duration expires\n";
    echo "   • No external task scheduler required\n";
    echo "   • Real-time schedule validation on every API call\n\n";

} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
?>
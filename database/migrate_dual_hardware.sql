-- ============================================================================
-- Database Migration: Dual Hardware Support (2 Fans + 2 Heaters)
-- Date: 2026-04-04
-- Description: Update schema to support dual fans and heaters with enhanced monitoring
-- ============================================================================

-- 1. Update drying_logs table for dual hardware tracking
ALTER TABLE `drying_logs` 
ADD COLUMN `fan1_state` tinyint(1) NOT NULL DEFAULT 0 AFTER `fan_state`,
ADD COLUMN `fan2_state` tinyint(1) NOT NULL DEFAULT 0 AFTER `fan1_state`,
ADD COLUMN `heater1_state` tinyint(1) NOT NULL DEFAULT 0 AFTER `heater_state`,
ADD COLUMN `heater2_state` tinyint(1) NOT NULL DEFAULT 0 AFTER `heater1_state`;

-- Keep existing fan_state and heater_state for backward compatibility
-- These will represent combined state (fan1_state OR fan2_state)

-- 2. Update phase enum to include 'Drying' phase
ALTER TABLE `drying_logs` 
MODIFY COLUMN `phase` enum('Heating','Exhaust','Cooldown','Idle','Drying') DEFAULT 'Idle';

-- 3. Create/Update live_sensor_cache table for enhanced real-time display
CREATE TABLE IF NOT EXISTS `live_sensor_cache` (
  `id` int(11) NOT NULL PRIMARY KEY DEFAULT 1,
  `temperature` decimal(5,2) DEFAULT NULL,
  `humidity` decimal(5,2) DEFAULT NULL,
  `fan1_state` tinyint(1) NOT NULL DEFAULT 0,
  `fan2_state` tinyint(1) NOT NULL DEFAULT 0,
  `heater1_state` tinyint(1) NOT NULL DEFAULT 0,
  `heater2_state` tinyint(1) NOT NULL DEFAULT 0,
  `exhaust_state` tinyint(1) NOT NULL DEFAULT 0,
  `phase` varchar(20) DEFAULT 'Idle',
  `timestamp` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Insert default record for live_sensor_cache if not exists
INSERT IGNORE INTO `live_sensor_cache` (id) VALUES (1);

-- 5. Add duration_hours column to batch_schedules for scheduled session duration
ALTER TABLE `batch_schedules` 
ADD COLUMN `duration_hours` decimal(4,2) NOT NULL DEFAULT 2.00 AFTER `set_humidity`;

-- 6. Add session timing enhancements to drying_sessions
-- (No changes needed - start_time and end_time already exist)

-- 7. Add index for better performance on drying_logs queries
CREATE INDEX IF NOT EXISTS `idx_session_timestamp` ON `drying_logs` (`session_id`, `timestamp`);

-- 8. Update drying_controls for enhanced control state
ALTER TABLE `drying_controls`
ADD COLUMN `cycle_count` int(11) NOT NULL DEFAULT 0 AFTER `cooldown_until`,
ADD COLUMN `last_cycle_start` datetime DEFAULT NULL AFTER `cycle_count`;

-- ============================================================================
-- Migration Complete
-- 
-- Summary of Changes:
-- - Added fan1_state, fan2_state, heater1_state, heater2_state to drying_logs
-- - Enhanced live_sensor_cache with individual device states  
-- - Added 'Drying' phase to phase enum
-- - Added duration_hours to batch_schedules for scheduled sessions
-- - Added cycle tracking to drying_controls
-- - Performance index on drying_logs
-- ============================================================================
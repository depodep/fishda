-- Create live_sensor_cache table for storing latest Arduino sensor readings
-- This table is updated every 3 seconds by sensor_api.php and displayed on idle dashboard

CREATE TABLE IF NOT EXISTS `live_sensor_cache` (
  `id` int(11) NOT NULL DEFAULT 1,
  `temperature` decimal(5,2) DEFAULT NULL COMMENT 'Latest temperature reading',
  `humidity` decimal(5,2) DEFAULT NULL COMMENT 'Latest humidity reading',
  `heater1_state` tinyint(1) DEFAULT 0 COMMENT 'Heater 1 state (0=OFF, 1=ON)',
  `heater2_state` tinyint(1) DEFAULT 0 COMMENT 'Heater 2 state (0=OFF, 1=ON)',
  `fan1_state` tinyint(1) DEFAULT 0 COMMENT 'Fan 1 state (0=OFF, 1=ON)',
  `fan2_state` tinyint(1) DEFAULT 0 COMMENT 'Fan 2 state (0=OFF, 1=ON)',
  `exhaust_state` tinyint(1) DEFAULT 0 COMMENT 'Exhaust state (0=OFF, 1=ON)',
  `phase` varchar(20) DEFAULT 'Idle' COMMENT 'Current phase (Idle, Heating, Drying, Cooldown)',
  `timestamp` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache for latest live sensor readings from Arduino';

-- Initialize with default row if not exists
INSERT INTO live_sensor_cache (id, temperature, humidity, phase)
VALUES (1, NULL, NULL, 'Idle')
ON DUPLICATE KEY UPDATE id=id;

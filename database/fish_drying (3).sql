-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 03, 2026 at 05:22 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fish_drying`
--

-- --------------------------------------------------------

--
-- Table structure for table `batch_schedules`
--

CREATE TABLE `batch_schedules` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(120) NOT NULL DEFAULT 'Tilapia Batch',
  `sched_date` date NOT NULL,
  `sched_time` time NOT NULL DEFAULT '08:00:00',
  `set_temp` decimal(5,2) NOT NULL DEFAULT 45.00,
  `set_humidity` decimal(5,2) NOT NULL DEFAULT 25.00,
  `notes` text DEFAULT NULL,
  `status` enum('Scheduled','Running','Done','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `batch_schedules`
--

INSERT INTO `batch_schedules` (`id`, `user_id`, `title`, `sched_date`, `sched_time`, `set_temp`, `set_humidity`, `notes`, `status`, `created_at`) VALUES
(1, 23, 'Fishda Batch A', '2026-04-04', '08:00:00', 33.00, 30.00, 'Temporary upcoming schedule seed', 'Scheduled', '2026-04-03 21:34:18'),
(2, 23, 'Aquadry Batch B', '2026-04-05', '09:30:00', 34.00, 32.00, 'Temporary upcoming schedule seed', 'Scheduled', '2026-04-03 21:34:18'),
(3, 23, 'HeatBot Batch C', '2026-04-06', '13:00:00', 35.00, 31.00, 'Temporary upcoming schedule seed', 'Scheduled', '2026-04-03 21:34:18'),
(4, 23, 'Fishda Batch D', '2026-04-07', '15:00:00', 32.00, 29.00, 'Temporary upcoming schedule seed', 'Scheduled', '2026-04-03 21:34:18'),
(5, 3, 'Tilapia Batch', '2026-04-03', '08:00:00', 50.00, 30.00, '', 'Scheduled', '2026-04-03 22:19:04');

-- --------------------------------------------------------

--
-- Table structure for table `drying_controls`
--

CREATE TABLE `drying_controls` (
  `id` int(11) NOT NULL,
  `status` varchar(20) DEFAULT 'STOPPED',
  `target_temp` float DEFAULT 0,
  `target_humidity` float DEFAULT 0,
  `start_time` datetime DEFAULT NULL,
  `cooldown_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drying_controls`
--

INSERT INTO `drying_controls` (`id`, `status`, `target_temp`, `target_humidity`, `start_time`, `cooldown_until`) VALUES
(1, 'STOPPED', 33, 30, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `drying_logs`
--

CREATE TABLE `drying_logs` (
  `log_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `recorded_temp` decimal(5,2) NOT NULL,
  `recorded_humidity` decimal(5,2) NOT NULL,
  `heater_state` tinyint(1) NOT NULL DEFAULT 0,
  `exhaust_state` tinyint(1) NOT NULL DEFAULT 0,
  `fan_state` tinyint(1) NOT NULL DEFAULT 0,
  `phase` enum('Heating','Exhaust','Cooldown','Idle') DEFAULT 'Idle',
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drying_logs`
--

INSERT INTO `drying_logs` (`log_id`, `session_id`, `recorded_temp`, `recorded_humidity`, `heater_state`, `exhaust_state`, `fan_state`, `phase`, `timestamp`) VALUES
(1, 1, 33.10, 39.00, 0, 0, 1, '', '2026-04-03 08:15:00'),
(2, 1, 33.00, 38.50, 0, 0, 1, '', '2026-04-03 08:17:00'),
(3, 2, 34.00, 40.00, 0, 1, 1, '', '2026-04-03 09:40:00'),
(4, 2, 34.20, 39.60, 0, 1, 1, '', '2026-04-03 09:52:00'),
(5, 3, 35.20, 38.50, 0, 1, 1, '', '2026-04-03 10:28:00'),
(6, 3, 35.00, 38.00, 0, 1, 1, '', '2026-04-03 10:40:00'),
(7, 4, 32.40, 41.20, 0, 0, 1, '', '2026-04-03 11:15:00'),
(8, 4, 32.10, 40.80, 0, 0, 1, '', '2026-04-03 11:21:00'),
(9, 5, 34.50, 37.80, 0, 1, 1, '', '2026-04-03 12:10:00'),
(10, 5, 34.70, 37.40, 0, 1, 1, '', '2026-04-03 12:20:00');

-- --------------------------------------------------------

--
-- Table structure for table `drying_records`
--

CREATE TABLE `drying_records` (
  `id` int(11) NOT NULL,
  `batch_id` varchar(100) DEFAULT 'Manual Batch',
  `duration` varchar(50) DEFAULT '00:00:00',
  `energy` float DEFAULT 0,
  `temp_avg` float DEFAULT 0,
  `hum_avg` float DEFAULT 0,
  `status` varchar(50) DEFAULT 'Completed',
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drying_records`
--

INSERT INTO `drying_records` (`id`, `batch_id`, `duration`, `energy`, `temp_avg`, `hum_avg`, `status`, `timestamp`, `user_id`, `session_id`) VALUES
(1, 'Fishda Batch A', '00:18:00', 0, 33, 38.7, 'Completed', '2026-04-03 13:34:18', 23, 1),
(2, 'Aquadry Batch B', '00:55:00', 0, 34.1, 39.8, 'Completed', '2026-04-03 13:34:18', 23, 2),
(3, 'HeatBot Batch C', '00:42:00', 0, 35.1, 38.2, 'Completed', '2026-04-03 13:34:18', 23, 3),
(4, 'Fishda Batch D', '00:23:00', 0, 32.3, 41, 'Completed', '2026-04-03 13:34:18', 23, 4),
(5, 'Aquadry Batch E', '00:33:00', 0, 34.6, 37.6, 'Completed', '2026-04-03 13:34:18', 23, 5);

-- --------------------------------------------------------

--
-- Table structure for table `drying_sessions`
--

CREATE TABLE `drying_sessions` (
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL DEFAULT current_timestamp(),
  `end_time` datetime DEFAULT NULL,
  `set_temp` decimal(5,2) NOT NULL DEFAULT 45.00,
  `set_humidity` decimal(5,2) NOT NULL DEFAULT 25.00,
  `status` enum('Running','Completed','Interrupted') NOT NULL DEFAULT 'Running',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drying_sessions`
--

INSERT INTO `drying_sessions` (`session_id`, `user_id`, `start_time`, `end_time`, `set_temp`, `set_humidity`, `status`, `notes`) VALUES
(1, 23, '2026-04-03 08:00:00', '2026-04-03 08:18:00', 33.00, 30.00, 'Completed', NULL),
(2, 23, '2026-04-03 09:00:00', '2026-04-03 09:55:00', 34.00, 32.00, 'Completed', NULL),
(3, 23, '2026-04-03 10:00:00', '2026-04-03 10:42:00', 35.00, 31.00, 'Completed', NULL),
(4, 23, '2026-04-03 11:00:00', '2026-04-03 11:23:00', 32.00, 30.00, 'Completed', NULL),
(5, 23, '2026-04-03 12:00:00', '2026-04-03 12:33:00', 34.00, 29.00, 'Completed', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sensor_readings`
--

CREATE TABLE `sensor_readings` (
  `id` int(11) NOT NULL,
  `temperature` decimal(5,2) NOT NULL,
  `humidity` decimal(5,2) NOT NULL,
  `session_id` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblusers`
--

CREATE TABLE `tblusers` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `permission` varchar(20) NOT NULL DEFAULT 'user',
  `status` int(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblusers`
--

INSERT INTO `tblusers` (`id`, `username`, `password`, `permission`, `status`) VALUES
(2, 'admin', '$2y$10$QjKJPQE0yGePrU470aQ6OezRbNIVcEHqOb85OLqKiyDByB/8yWQKi', 'admin', 1),
(3, 'DINDO', '$2y$10$pLF4xAnnmdHdMW/HCNI6Q.o67R8SJmElHQ0uBPJpk7Nis7jNl3QZ.', 'user', 1),
(5, 'david', '$2y$10$jBORFjfeETJocGtq4qUjAuYqohM.bB5Pb5ZyqsINTYsTCuvtqQHLa', 'user', 0),
(6, 'jouana', '$2y$10$4F9PZ1MVuWhOEYc7.6c3Y.ijkk95pAliVkUzpoyB1fpX2m.HwyXse', 'user', 1),
(12, 'doma', '$2y$10$PpT9VgKLmrAcB/XwD8EjjOJu.uUm.IzvzknaIu5P5Td6oRnBURQU2', 'user', 1),
(17, 'vb', '$2y$10$3bZ2BUbfK18o32Sz5sAuZuBXXMX0oY8bv2P4szcT6uT2ueTAQV192', 'user', 1),
(18, 'dhns', '$2y$10$NatHwS8z7jkLvk00x7a8YehsURKHXRydqt9J90UZVv7exjgrdgTYO', 'user', 1),
(20, 'nica', '$2y$10$mjJ5CVi3U3IH5YUKV4hL...dls.OYte9LkMyB0lC174U21xOkWGoW', 'user', 0),
(22, 'dorina', '$2y$10$pZBcLBRhS1M81jjMZQVcl.uHHBVcCXa2PCCWEtqK7Gbf0Q8CIFEyO', 'user', 1),
(23, 'neri', '$2y$10$bWrpSH9Dq5.hQ0f3BP.peO2/d3f3YDSQgJahLpFdSNcjqzdf5/R26', 'user', 1),
(24, 'aji', '$2y$10$3RgNbXzD3JnkClWLhOJkOeVhgy9dhVP.eKo7eAEjLLHEMRosxrnPm', 'user', 1),
(25, 'joy', '$2y$10$/QomeTNJbuBCTwY2DAVG2Oxh1J3IuqVTokik2du7lFSv6arfcuToa', 'user', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_admin`
--

CREATE TABLE `tbl_admin` (
  `id` int(11) NOT NULL,
  `username` varchar(80) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'bcrypt hashed',
  `full_name` varchar(150) DEFAULT NULL,
  `contact_no` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System administrator accounts';

--
-- Dumping data for table `tbl_admin`
--

INSERT INTO `tbl_admin` (`id`, `username`, `password`, `full_name`, `contact_no`, `email`, `status`, `created_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', '+63 912 345 6789', 'admin@protoautosys.edu.ph', 1, '2026-04-03 06:38:42');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_inquiries`
--

CREATE TABLE `tbl_inquiries` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `contact` varchar(100) DEFAULT NULL COMMENT 'Phone or email of inquirer',
  `message` text NOT NULL,
  `status` enum('pending','read','replied') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Order and contact inquiries from the landing page';

-- --------------------------------------------------------

--
-- Table structure for table `tbl_prototypes`
--

CREATE TABLE `tbl_prototypes` (
  `id` int(11) NOT NULL,
  `model_name` varchar(100) NOT NULL COMMENT 'Brand or model name (e.g. Fishda)',
  `given_code` varchar(50) NOT NULL COMMENT 'Unique access code assigned by admin (e.g. FD2026)',
  `description` text DEFAULT NULL COMMENT 'Optional notes about this prototype',
  `owner_name` varchar(150) DEFAULT NULL COMMENT 'Person or unit this prototype belongs to',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Active, 0 = Restricted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registered prototypes that can log in to the system';

--
-- Dumping data for table `tbl_prototypes`
--

INSERT INTO `tbl_prototypes` (`id`, `model_name`, `given_code`, `description`, `owner_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Fishda', 'FD2026', 'Temporary prototype model for testing', 'Temporary Seed', 1, '2026-04-03 13:27:05', '2026-04-03 14:42:54'),
(2, 'Aquadry', 'AQ2026', 'Temporary solar drying model', 'Temporary Seed', 1, '2026-04-03 13:27:05', '2026-04-03 13:27:05'),
(3, 'HeatBot', 'HB2026', 'Temporary heat chamber model', 'Temporary Seed', 0, '2026-04-03 13:27:05', '2026-04-03 13:27:05');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_sessions`
--

CREATE TABLE `tbl_sessions` (
  `id` int(11) NOT NULL,
  `prototype_id` int(11) NOT NULL COMMENT 'FK → tbl_prototypes.id',
  `login_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `logout_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Login/session history per prototype';

--
-- Dumping data for table `tbl_sessions`
--

INSERT INTO `tbl_sessions` (`id`, `prototype_id`, `login_at`, `logout_at`, `ip_address`, `notes`) VALUES
(1, 1, '2026-04-03 13:43:46', NULL, '::1', NULL),
(2, 1, '2026-04-03 15:03:09', NULL, '::1', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `temp`
--

CREATE TABLE `temp` (
  `id` int(11) NOT NULL,
  `temperature` float DEFAULT NULL,
  `fan_status` tinyint(1) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `batch_schedules`
--
ALTER TABLE `batch_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sched_user` (`user_id`),
  ADD KEY `idx_sched_date` (`sched_date`);

--
-- Indexes for table `drying_controls`
--
ALTER TABLE `drying_controls`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `drying_logs`
--
ALTER TABLE `drying_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_logs_session` (`session_id`),
  ADD KEY `idx_logs_timestamp` (`timestamp`);

--
-- Indexes for table `drying_records`
--
ALTER TABLE `drying_records`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `drying_sessions`
--
ALTER TABLE `drying_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `idx_sessions_user` (`user_id`);

--
-- Indexes for table `sensor_readings`
--
ALTER TABLE `sensor_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sensor_ts` (`timestamp`),
  ADD KEY `idx_sensor_session` (`session_id`);

--
-- Indexes for table `tblusers`
--
ALTER TABLE `tblusers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `tbl_admin`
--
ALTER TABLE `tbl_admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_username` (`username`);

--
-- Indexes for table `tbl_inquiries`
--
ALTER TABLE `tbl_inquiries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `tbl_prototypes`
--
ALTER TABLE `tbl_prototypes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_model_code` (`model_name`,`given_code`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `tbl_sessions`
--
ALTER TABLE `tbl_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prototype_id` (`prototype_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `batch_schedules`
--
ALTER TABLE `batch_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `drying_controls`
--
ALTER TABLE `drying_controls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `drying_logs`
--
ALTER TABLE `drying_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `drying_records`
--
ALTER TABLE `drying_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `drying_sessions`
--
ALTER TABLE `drying_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `sensor_readings`
--
ALTER TABLE `sensor_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tblusers`
--
ALTER TABLE `tblusers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `tbl_admin`
--
ALTER TABLE `tbl_admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_inquiries`
--
ALTER TABLE `tbl_inquiries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_prototypes`
--
ALTER TABLE `tbl_prototypes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_sessions`
--
ALTER TABLE `tbl_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `batch_schedules`
--
ALTER TABLE `batch_schedules`
  ADD CONSTRAINT `batch_schedules_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tblusers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `drying_logs`
--
ALTER TABLE `drying_logs`
  ADD CONSTRAINT `drying_logs_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `drying_sessions` (`session_id`) ON DELETE CASCADE;

--
-- Constraints for table `drying_sessions`
--
ALTER TABLE `drying_sessions`
  ADD CONSTRAINT `drying_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tblusers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_sessions`
--
ALTER TABLE `tbl_sessions`
  ADD CONSTRAINT `fk_session_prototype` FOREIGN KEY (`prototype_id`) REFERENCES `tbl_prototypes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

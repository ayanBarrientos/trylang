-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 07, 2025 at 11:43 AM
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
-- Database: `vacansee_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `reservation_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `faculty_viewed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL,
  `room_code` varchar(20) NOT NULL,
  `room_name` varchar(100) NOT NULL,
  `department` enum('Engineering','DCE') NOT NULL,
  `capacity` int(11) NOT NULL,
  `has_aircon` tinyint(1) DEFAULT 0,
  `has_projector` tinyint(1) DEFAULT 0,
  `has_computers` tinyint(1) DEFAULT 0,
  `has_whiteboard` tinyint(1) DEFAULT 1,
  `is_available` tinyint(1) DEFAULT 1,
  `status` enum('vacant','occupied','reserved','maintenance') DEFAULT 'vacant',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`id`, `room_code`, `room_name`, `department`, `capacity`, `has_aircon`, `has_projector`, `has_computers`, `has_whiteboard`, `is_available`, `status`, `created_at`, `updated_at`) VALUES
(1, 'R-V1', 'Room V1', 'Engineering', 50, 1, 1, 0, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(2, 'R-V2', 'Room V2', 'Engineering', 45, 1, 0, 0, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(3, 'R-V3', 'Room V3', 'Engineering', 60, 0, 1, 0, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(4, 'R-V4', 'Room V4', 'Engineering', 40, 1, 1, 1, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(5, 'ENG-COMLAB', 'Engineering Computer Lab', 'Engineering', 30, 1, 1, 1, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(6, 'ELECTRONICS LAB', 'Electronics Laboratory', 'Engineering', 25, 1, 0, 1, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(7, 'PHYSICS LAB', 'Physics Laboratory', 'Engineering', 30, 1, 1, 0, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(8, 'DRAWING ROOM', 'Drawing Room', 'Engineering', 35, 0, 0, 0, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(9, 'CHEM LAB', 'Chemistry Laboratory', 'Engineering', 20, 1, 0, 0, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(10, 'COMLAB V1', 'Computer Lab V1', 'DCE', 35, 1, 1, 1, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(11, 'COMLAB V2', 'Computer Lab V2', 'DCE', 35, 1, 1, 1, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(12, 'COMLAB V3', 'Computer Lab V3', 'DCE', 40, 1, 1, 1, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(13, 'R-301', 'Room 301', 'DCE', 55, 0, 1, 0, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(14, 'R-302', 'Room 302', 'DCE', 55, 1, 0, 0, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18'),
(15, 'AVR', 'Audio Visual Room', 'DCE', 100, 1, 1, 1, 1, 1, 'vacant', '2025-12-07 09:40:18', '2025-12-07 09:40:18');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `subject_code` varchar(20) DEFAULT NULL,
  `subject_name` varchar(100) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `faculty_leave_notes`
--

CREATE TABLE `faculty_leave_notes` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `leave_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `faculty_leave_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

ALTER TABLE `faculty_leave_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `user_type` enum('admin','faculty','student') NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `first_name`, `last_name`, `user_type`, `department`, `created_at`, `updated_at`, `is_active`) VALUES
(1, 'admin@umindanao.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEaHlE89a0UPY0n2/1uixJ8/.X2', 'Admin', 'System', 'admin', 'Administration', '2025-12-07 09:40:18', '2025-12-07 09:40:18', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `room_code` (`room_code`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `schedules_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

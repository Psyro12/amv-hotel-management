-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 27, 2025 at 04:17 AM
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
-- Database: `amv_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_user`
--

CREATE TABLE `admin_user` (
  `ID` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_user`
--

INSERT INTO `admin_user` (`ID`, `name`, `email`, `password`) VALUES
(1, 'AMV Hotel', 'amvbuilding.occmdo@gmail.com', '$2y$10$BSr7Ac5eCCZg.q.M9k4ieORmMkt/FwKc/d4rj1yfGzmJLE6DDD0ua');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `uid` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `photo` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `provider` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `uid`, `name`, `email`, `photo`, `created_at`, `updated_at`, `provider`) VALUES
(1, 'ISv3Mb5GJWd6wMqDRUhZg3JK8Rl1', 'Kirt Luc\'z', 'zyropros@gmail.com', 'https://lh3.googleusercontent.com/a/ACg8ocLgwXkW1mBLxXRYjE1me4HGieEferLVkgkBB93HJ6xxrADZEw=s96-c', '2025-11-21 05:40:37', '2025-11-21 05:40:37', ''),
(2, 'EP0o4aSj6eekPfje8w6gloNiE9T2', 'Kirt allen Lucaylucay', 'zyromatrix@gmail.com', 'https://lh3.googleusercontent.com/a/ACg8ocJqRPQxke1WQDUUiRE5NNvhDlb7UwZjcJ8z4Z_M7TWTVH7ngg=s96-c', '2025-11-21 06:15:36', '2025-11-21 06:15:36', ''),
(3, 'VBfvF5bFNYhIUGmOJThV9xRJoPg2', 'Arren Periol', 'periolarren@gmail.com', 'https://lh3.googleusercontent.com/a/ACg8ocJ01jCUk11GWzQxfkvOc-96CcIrXdgoZSzYpTijnWKn0xprFw=s96-c', '2025-11-24 11:29:06', '2025-11-24 11:29:06', ''),
(4, 'D9V9msXbJHczUwTXTSBQ7OlGBGX2', 'Allen Dave Lucaylucay', '', 'https://graph.facebook.com/1140489971583569/picture', '2025-11-24 14:54:13', '2025-11-24 14:54:13', '');

-- --------------------------------------------------------

--
-- Table structure for table `user_info`
--

CREATE TABLE `user_info` (
  `id` int(11) NOT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone_no` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_info`
--

INSERT INTO `user_info` (`id`, `user_name`, `user_email`, `birthdate`, `address`, `phone_no`) VALUES
(4, 'Kirt Luc\'z', 'zyropros@gmail.com', '2004-02-18', 'MIMAROPA Region, Occidental Mindoro, Mamburao, Balansay', '09123123441'),
(5, 'Allen Dave Lucaylucay', 'allendavelucaylucay03@gmail.com', NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_user`
--
ALTER TABLE `admin_user`
  ADD PRIMARY KEY (`ID`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_info`
--
ALTER TABLE `user_info`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_user`
--
ALTER TABLE `admin_user`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_info`
--
ALTER TABLE `user_info`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- SQL script to add cancellation request functionality
CREATE TABLE IF NOT EXISTS `cancellation_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add flag to bookings table to track pending requests
ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `cancel_requested` TINYINT(1) DEFAULT 0;

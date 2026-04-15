-- SQL Setup for AMV Hotel Real-Time System
-- Run this in your database (e.g., phpMyAdmin) to enable the SSE updates.

-- 1. Create the system_updates table
CREATE TABLE IF NOT EXISTS `system_updates` (
  `category` varchar(50) NOT NULL,
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Initialize the standard categories used by the dashboard
-- This ensures sse_updates.php has data to compare against.
INSERT INTO `system_updates` (`category`, `last_updated`) VALUES
('bookings', CURRENT_TIMESTAMP),
('food_orders', CURRENT_TIMESTAMP),
('transactions', CURRENT_TIMESTAMP),
('notifications', CURRENT_TIMESTAMP),
('messages', CURRENT_TIMESTAMP),
('rooms', CURRENT_TIMESTAMP),
('guests', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE last_updated = CURRENT_TIMESTAMP;

-- 3. (Optional) Example of how to trigger an update in your PHP code:
-- UPDATE system_updates SET last_updated = CURRENT_TIMESTAMP WHERE category = 'bookings';

<?php
// FILE: setup_cancellation_feature.php
require 'ADMIN/PHP/db_connect.php';

echo "<h2>AMV Hotel - Cancellation Feature Setup</h2>";

$sql = "CREATE TABLE IF NOT EXISTS `cancellation_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql)) {
    echo "<p style='color:green;'>✅ Table 'cancellation_requests' created or already exists.</p>";
} else {
    echo "<p style='color:red;'>❌ Error creating table: " . $conn->error . "</p>";
}

// Add column to bookings if not exists
$checkColumn = $conn->query("SHOW COLUMNS FROM `bookings` LIKE 'cancel_requested'");
if ($checkColumn->num_rows == 0) {
    if ($conn->query("ALTER TABLE `bookings` ADD COLUMN `cancel_requested` TINYINT(1) DEFAULT 0")) {
        echo "<p style='color:green;'>✅ Column 'cancel_requested' added to 'bookings' table.</p>";
    } else {
        echo "<p style='color:red;'>❌ Error adding column: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color:blue;'>ℹ️ Column 'cancel_requested' already exists in 'bookings'.</p>";
}

// Check system_updates table for the category
$checkUpdate = $conn->query("SELECT * FROM system_updates WHERE category = 'cancellation_requests'");
if ($checkUpdate->num_rows == 0) {
    if ($conn->query("INSERT INTO system_updates (category) VALUES ('cancellation_requests')")) {
        echo "<p style='color:green;'>✅ SSE category 'cancellation_requests' initialized.</p>";
    } else {
        echo "<p style='color:red;'>❌ Error initializing SSE: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color:blue;'>ℹ️ SSE category 'cancellation_requests' already exists.</p>";
}

echo "<br><a href='ADMIN/PHP/dashboard.php'>Go to Dashboard</a>";
?>

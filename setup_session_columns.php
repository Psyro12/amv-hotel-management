<?php
// setup_session_columns.php
require 'ADMIN/PHP/db_connect.php';

$sql = "ALTER TABLE `admin_user` ADD COLUMN IF NOT EXISTS `last_activity` DATETIME NULL";

if ($conn->query($sql)) {
    echo "SUCCESS: last_activity column added or already exists.\n";
} else {
    echo "ERROR: " . $conn->error . "\n";
}

$conn->close();
?>

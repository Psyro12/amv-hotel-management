-- SQL Update for AMV Hotel Management System
-- Use this to update the 'admin_user' table on your hosted server.

ALTER TABLE `admin_user` 
ADD COLUMN `last_ip` VARCHAR(45) NULL DEFAULT NULL AFTER `last_activity`,
ADD COLUMN `last_session_id` VARCHAR(255) NULL DEFAULT NULL AFTER `last_ip`;

-- Multi-stop campus visits: one gate pass and one tracking session covering two or
-- three office stops on the same day. Each stop remains an ordinary appointment row,
-- so office approval, availability, rescheduling, and office-scoped access are unchanged.
-- Run after mobile_api_migration.sql. Additive and safe to run more than once on MariaDB 10.4+.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visit_code` varchar(24) DEFAULT NULL,
  `visitor_user_id` int(11) NOT NULL,
  `public_token` char(64) NOT NULL,
  `visit_date` date NOT NULL,
  `status` enum('open','checked_in','completed','closed') NOT NULL DEFAULT 'open',
  `tracking_appointment_id` int(11) DEFAULT NULL COMMENT 'Stop that owns the visit tracking session and GPS points',
  `checked_in_at` datetime DEFAULT NULL,
  `checked_in_by_user_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by_user_id` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visits_public_token` (`public_token`),
  UNIQUE KEY `uq_visits_visit_code` (`visit_code`),
  KEY `idx_visits_visitor_date` (`visitor_user_id`,`visit_date`,`status`),
  KEY `idx_visits_status` (`status`),
  KEY `idx_visits_tracking_appointment` (`tracking_appointment_id`),
  CONSTRAINT `fk_visits_visitor` FOREIGN KEY (`visitor_user_id`) REFERENCES `app_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_visits_checked_in_by` FOREIGN KEY (`checked_in_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_completed_by` FOREIGN KEY (`completed_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `visit_id` int(11) DEFAULT NULL AFTER `visitor_user_id`,
  ADD COLUMN IF NOT EXISTS `stop_number` tinyint(3) unsigned DEFAULT NULL AFTER `visit_id`,
  ADD KEY IF NOT EXISTS `idx_appointments_visit` (`visit_id`,`scheduled_start_at`);

-- Add the two cross-table foreign keys only when they do not already exist.
DROP PROCEDURE IF EXISTS `multi_stop_add_fk`;
DELIMITER //
CREATE PROCEDURE `multi_stop_add_fk`(
  IN p_table_name varchar(64),
  IN p_constraint_name varchar(64),
  IN p_ddl text
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table_name
      AND CONSTRAINT_NAME = p_constraint_name
  ) THEN
    SET @multi_stop_ddl = p_ddl;
    PREPARE multi_stop_stmt FROM @multi_stop_ddl;
    EXECUTE multi_stop_stmt;
    DEALLOCATE PREPARE multi_stop_stmt;
  END IF;
END//
DELIMITER ;

CALL `multi_stop_add_fk`('appointments', 'fk_appointments_visit',
  'ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE SET NULL');
CALL `multi_stop_add_fk`('visits', 'fk_visits_tracking_appointment',
  'ALTER TABLE `visits` ADD CONSTRAINT `fk_visits_tracking_appointment` FOREIGN KEY (`tracking_appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL');

DROP PROCEDURE IF EXISTS `multi_stop_add_fk`;

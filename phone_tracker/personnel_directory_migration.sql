-- Department directory, staff names, and activity accountability.
-- Import after auth_security_migration.sql. Safe to run repeatedly on the MariaDB used by XAMPP.

SET NAMES utf8mb4;

-- Departments that Office Personnel belong to and visitors book appointments with.
-- Archived departments (is_active = 0) take no new appointments or personnel but keep
-- their name on historical records. `code` is what appointments and accounts store.
CREATE TABLE IF NOT EXISTS `offices` (
  `code` varchar(16) NOT NULL,
  `name` varchar(100) NOT NULL,
  `location` varchar(150) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`code`),
  UNIQUE KEY `uq_offices_name` (`name`),
  KEY `idx_offices_active` (`is_active`, `sort_order`),
  CONSTRAINT `fk_offices_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `offices` (`code`, `name`, `sort_order`) VALUES
  ('IT', 'IT Department', 1),
  ('IS', 'IS Department', 2),
  ('CS', 'CS Department', 3),
  ('DEANS', 'Dean''s Office', 4),
  ('TECH_SUPPORT', 'Tech Support', 5)
ON DUPLICATE KEY UPDATE `code` = `code`;

-- Who is behind each account. display_name stays as "First Last" so every existing
-- screen keeps working; staff names are managed by administrators.
ALTER TABLE `app_users`
  ADD COLUMN IF NOT EXISTS `first_name` varchar(60) NOT NULL DEFAULT '' AFTER `display_name`,
  ADD COLUMN IF NOT EXISTS `last_name` varchar(60) NOT NULL DEFAULT '' AFTER `first_name`,
  ADD COLUMN IF NOT EXISTS `position` varchar(100) NOT NULL DEFAULT '' AFTER `last_name`,
  ADD COLUMN IF NOT EXISTS `last_seen_at` datetime DEFAULT NULL;

-- The activity log filters by date.
ALTER TABLE `audit_logs`
  ADD KEY IF NOT EXISTS `idx_audit_created` (`created_at`);

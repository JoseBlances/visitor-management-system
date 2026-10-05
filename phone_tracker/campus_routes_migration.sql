-- Walking routes an administrator records on the Campus Map page (Routes tab), and how
-- each gate and office pin was placed (on the map, or from the administrator's GPS
-- position with its accuracy). Safe to run more than once.

SET NAMES utf8mb4;

ALTER TABLE `campus_places`
  ADD COLUMN IF NOT EXISTS `accuracy_meters` decimal(5,1) DEFAULT NULL AFTER `longitude`,
  ADD COLUMN IF NOT EXISTS `placed_by` varchar(8) NOT NULL DEFAULT 'map' AFTER `accuracy_meters`;

-- One row per route from a starting point (usually a gate) to a department. points_json
-- holds the path as [[latitude, longitude], ...] from the start to the department.
CREATE TABLE IF NOT EXISTS `campus_routes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `office_code` varchar(16) NOT NULL,
  `name` varchar(120) NOT NULL,
  `start_label` varchar(100) NOT NULL DEFAULT '',
  `method` varchar(8) NOT NULL DEFAULT 'walked',
  `points_json` longtext NOT NULL,
  `point_count` int(11) NOT NULL DEFAULT 0,
  `distance_meters` decimal(8,1) NOT NULL DEFAULT 0.0,
  `duration_seconds` int(11) DEFAULT NULL,
  `average_accuracy_meters` decimal(5,1) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `updated_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campus_routes_office` (`office_code`),
  CONSTRAINT `fk_campus_routes_office` FOREIGN KEY (`office_code`) REFERENCES `offices` (`code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_campus_routes_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_campus_routes_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

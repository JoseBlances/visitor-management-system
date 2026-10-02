-- Campus boundary, gate, and office pins drawn by the admin on the Campus Map page.
-- Boundary corners are stored in drawing order (sort_order) and form one polygon.

CREATE TABLE IF NOT EXISTS `campus_places` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `place_type` enum('boundary','gate','office') NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `updated_by_user_id` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campus_places_type` (`place_type`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

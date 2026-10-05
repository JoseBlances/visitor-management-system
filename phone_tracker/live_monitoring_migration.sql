-- Live visitor monitoring: inside/outside-campus state and how each visit ended.
-- Import after the migrations listed in TEAMMATE_SETUP.md. Safe to run again.
--
-- Privacy: positions outside the campus boundary are never stored. visitor_presence
-- only keeps the time a visitor stepped outside, never where they went.

CREATE TABLE IF NOT EXISTS `visitor_presence` (
  `appointment_id` int(11) NOT NULL COMMENT 'Appointment that owns the tracking session (the tracking stop of a multi-office visit)',
  `state` enum('inside','outside') NOT NULL DEFAULT 'inside',
  `outside_since` datetime DEFAULT NULL,
  `outside_readings` int(11) NOT NULL DEFAULT 0,
  `last_report_at` datetime DEFAULT NULL COMMENT 'Time of the newest reading from the phone, inside or outside',
  `last_inside_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`appointment_id`),
  KEY `idx_presence_state` (`state`, `outside_since`),
  CONSTRAINT `fk_presence_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- How a visit ended: scan (second scan at the gate), guard (End visit button),
-- left_campus (confirmed campus exit), end_of_day (still open at the end of the day).
-- NULL for visits that ended before this update.
ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `checkout_method` varchar(20) DEFAULT NULL AFTER `completed_by_user_id`;

ALTER TABLE `visits`
  ADD COLUMN IF NOT EXISTS `checkout_method` varchar(20) DEFAULT NULL AFTER `completed_by_user_id`;

-- A tracking session ended by a confirmed campus exit (MAP_GEOFENCE_HANDOFF.md).
ALTER TABLE `location_tracking_sessions`
  MODIFY `ended_reason` enum('completed','consent_withdrawn','window_ended','manual','campus_exit') DEFAULT NULL;

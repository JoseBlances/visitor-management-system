-- Confirmed arrival at an office stop (VISITOR_NAVIGATION.md). The visitor app reports it
-- when GPS places the visitor at the office pin (within the arrival distance, with an
-- accurate reading, for a few seconds) or when the visitor taps "I'm here". Security, the
-- office, and the activity log show it.
-- Import after live_monitoring_migration.sql. Safe to run again.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `arrived_at` datetime DEFAULT NULL AFTER `checked_in_at`,
  ADD COLUMN IF NOT EXISTS `arrival_method` varchar(10) DEFAULT NULL AFTER `arrived_at`,
  ADD COLUMN IF NOT EXISTS `arrival_distance_meters` decimal(6,1) DEFAULT NULL AFTER `arrival_method`,
  ADD COLUMN IF NOT EXISTS `arrival_accuracy_meters` decimal(6,1) DEFAULT NULL AFTER `arrival_distance_meters`;

-- How close (in meters, 1 to 30) the visitor's GPS must place them to the office pin to
-- count as arrived. Changing it needs no app update.
INSERT IGNORE INTO `mobile_api_settings` (`setting_key`, `setting_value`) VALUES ('arrival_distance_meters', '3');

-- The ISATU campus map as set up by the administrator on 2026-10-04: the campus boundary
-- (11 corners) and the pins of the CS Department, Dean's Office, IT Department, and
-- IS Department. No gates had been placed yet.
--
-- Import it once on a new server, after the migrations (DEPLOYMENT.md). It REPLACES
-- whatever map the database has, so do not import it over a map edited later; adjust the
-- map in the admin dashboard (Campus Map) instead.
START TRANSACTION;
DELETE FROM `campus_places`;
INSERT INTO `campus_places` (`place_type`, `office_code`, `name`, `latitude`, `longitude`, `sort_order`, `updated_by_user_id`) VALUES
('boundary', NULL, 'Campus boundary', 10.7159413, 122.5661373, 0, NULL),
('boundary', NULL, 'Campus boundary', 10.7168858, 122.5672496, 1, NULL),
('boundary', NULL, 'Campus boundary', 10.7171968, 122.5670082, 2, NULL),
('boundary', NULL, 'Campus boundary', 10.7175711, 122.5674374, 3, NULL),
('boundary', NULL, 'Campus boundary', 10.7168858, 122.5680277, 4, NULL),
('boundary', NULL, 'Campus boundary', 10.7154733, 122.5664662, 5, NULL),
('boundary', NULL, 'Campus boundary', 10.7147037, 122.5670779, 6, NULL),
('boundary', NULL, 'Campus boundary', 10.7133333, 122.5654897, 7, NULL),
('boundary', NULL, 'Campus boundary', 10.7171009, 122.5652200, 8, NULL),
('boundary', NULL, 'Campus boundary', 10.7168795, 122.5653865, 9, NULL),
('boundary', NULL, 'Campus boundary', 10.7162892, 122.5658530, 10, NULL),
('office', 'CS', 'CS Department', 10.7159619, 122.5664621, 0, NULL),
('office', 'DEANS', 'Dean\'s Office', 10.7159961, 122.5664996, 1, NULL),
('office', 'IT', 'IT Department', 10.7160383, 122.5665533, 2, NULL),
('office', 'IS', 'IS Department', 10.7160699, 122.5665855, 3, NULL);
COMMIT;

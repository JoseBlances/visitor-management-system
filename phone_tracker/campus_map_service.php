<?php
require_once __DIR__ . "/appointment_offices.php";

const CAMPUS_PLACES_TABLE_SQL = "CREATE TABLE IF NOT EXISTS `campus_places` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

function campus_map_ensure_table(mysqli $conn): bool
{
    return (bool) $conn->query(CAMPUS_PLACES_TABLE_SQL);
}

/**
 * @return array{configured: bool, boundary: array<int, array{0: float, 1: float}>,
 *               gates: array<int, array<string, mixed>>, offices: array<int, array<string, mixed>>,
 *               updated_at: ?string}
 */
function campus_map_load(mysqli $conn): array
{
    $campus = ["configured" => false, "boundary" => [], "gates" => [], "offices" => [], "updated_at" => null];
    $exists = $conn->query("SHOW TABLES LIKE 'campus_places'");
    if (!$exists || $exists->num_rows === 0) {
        return $campus;
    }

    $result = $conn->query(
        "SELECT place_type, office_code, name, latitude, longitude, updated_at
         FROM campus_places
         ORDER BY place_type ASC, sort_order ASC, id ASC"
    );
    if (!$result) {
        return $campus;
    }
    while ($row = $result->fetch_assoc()) {
        $latitude = (float) $row["latitude"];
        $longitude = (float) $row["longitude"];
        if ($campus["updated_at"] === null || $row["updated_at"] > $campus["updated_at"]) {
            $campus["updated_at"] = $row["updated_at"];
        }
        if ($row["place_type"] === "boundary") {
            $campus["boundary"][] = [$latitude, $longitude];
        } elseif ($row["place_type"] === "gate") {
            $campus["gates"][] = ["name" => $row["name"], "latitude" => $latitude, "longitude" => $longitude];
        } else {
            $code = (string) $row["office_code"];
            $campus["offices"][] = [
                "code" => $code,
                "label" => appointment_office_label($code),
                "latitude" => $latitude,
                "longitude" => $longitude,
            ];
        }
    }
    $campus["configured"] = count($campus["boundary"]) >= 3;
    return $campus;
}

function campus_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $toRad = M_PI / 180;
    $dLat = ($lat2 - $lat1) * $toRad;
    $dLng = ($lng2 - $lng1) * $toRad;
    $h = sin($dLat / 2) ** 2 + cos($lat1 * $toRad) * cos($lat2 * $toRad) * sin($dLng / 2) ** 2;
    return 2 * 6371000 * asin(min(1, sqrt($h)));
}

/**
 * Shortest distance from a point to the boundary outline, using a local flat projection.
 *
 * @param array<int, array{0: float, 1: float}> $boundary
 */
function campus_distance_to_boundary_meters(array $boundary, float $latitude, float $longitude): float
{
    $metersPerLat = 111320;
    $metersPerLng = 111320 * cos(deg2rad($latitude));
    $nearest = INF;
    $count = count($boundary);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $ax = ($boundary[$j][1] - $longitude) * $metersPerLng;
        $ay = ($boundary[$j][0] - $latitude) * $metersPerLat;
        $bx = ($boundary[$i][1] - $longitude) * $metersPerLng;
        $by = ($boundary[$i][0] - $latitude) * $metersPerLat;
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lengthSquared = $dx * $dx + $dy * $dy;
        $t = $lengthSquared > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $lengthSquared)) : 0;
        $nearest = min($nearest, hypot($ax + $t * $dx, $ay + $t * $dy));
    }
    return $nearest;
}

/**
 * Ray-casting point-in-polygon test. The campus is small enough to treat lat/lng as flat.
 *
 * @param array<int, array{0: float, 1: float}> $boundary
 */
function campus_contains(array $boundary, float $latitude, float $longitude): bool
{
    $inside = false;
    $count = count($boundary);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        [$latI, $lngI] = $boundary[$i];
        [$latJ, $lngJ] = $boundary[$j];
        if (($latI > $latitude) !== ($latJ > $latitude)
            && $longitude < ($lngJ - $lngI) * ($latitude - $latI) / ($latJ - $latI) + $lngI) {
            $inside = !$inside;
        }
    }
    return $inside;
}

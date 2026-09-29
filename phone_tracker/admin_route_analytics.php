<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/analytics_period.php";
require_once __DIR__ . "/campus_map_service.php";

require_admin_json();

// Match the local MySQL/system clock used by the ISATU installation.
date_default_timezone_set("Asia/Manila");

// GPS points are snapped to a grid so that visitors walking the same path share segments.
const ROUTE_CELL_METERS = 12;
// Readings less accurate than this are too vague to place on a walkway.
const ROUTE_MAX_ACCURACY_METERS = 50;
// A larger gap between two readings is treated as lost signal, not a walked segment.
const ROUTE_MAX_STEP_METERS = 120;
// A route's first/last point this close to a gate counts as entering/leaving through it.
const ROUTE_GATE_RADIUS_METERS = 60;
// A visitor this close to their office pin has arrived.
const ROUTE_OFFICE_RADIUS_METERS = 30;
const ROUTE_SEGMENT_LIMIT = 1500;
const METERS_PER_DEGREE_LATITUDE = 111320;

[$period, $start, $now] = analytics_period_range((string) ($_GET["period"] ?? "month"));
$startSql = $start->format("Y-m-d H:i:s");
$endSql = $now->format("Y-m-d H:i:s");
$officeMap = appointment_office_map();
$office = strtoupper(trim((string) ($_GET["office"] ?? "")));
if ($office !== "" && !isset($officeMap[$office])) {
    $office = "";
}

$campus = campus_map_load($conn);
$officePins = [];
foreach ($campus["offices"] as $pin) {
    $officePins[$pin["code"]] = [$pin["latitude"], $pin["longitude"]];
}

$stmt = $conn->prepare(
    "SELECT l.appointment_id, a.office_code, l.latitude, l.longitude, l.recorded_at
     FROM locations l
     INNER JOIN appointments a ON a.id = l.appointment_id
     WHERE a.checked_in_at BETWEEN ? AND ?
       AND l.recorded_at >= a.checked_in_at
       AND (a.completed_at IS NULL OR l.recorded_at <= a.completed_at)
       AND (l.accuracy IS NULL OR l.accuracy <= ?)
     ORDER BY l.appointment_id ASC, l.recorded_at ASC, l.id ASC"
);
$maxAccuracy = ROUTE_MAX_ACCURACY_METERS;
$stmt->bind_param("ssi", $startSql, $endSql, $maxAccuracy);
$stmt->execute();
$result = $stmt->get_result();

$routes = [];
$pointsOutsideCampus = 0;
while ($row = $result->fetch_assoc()) {
    $latitude = (float) $row["latitude"];
    $longitude = (float) $row["longitude"];
    // Only the ISATU campus is analysed; the walk to and from campus is ignored.
    if ($campus["configured"] && !campus_contains($campus["boundary"], $latitude, $longitude)) {
        $pointsOutsideCampus++;
        continue;
    }
    $appointmentId = (int) $row["appointment_id"];
    if (!isset($routes[$appointmentId])) {
        $routes[$appointmentId] = [
            "office_code" => (string) ($row["office_code"] ?? ""),
            "points" => [],
        ];
    }
    $routes[$appointmentId]["points"][] = [$latitude, $longitude, strtotime((string) $row["recorded_at"])];
}
$stmt->close();
$conn->close();

// Fix the grid to one reference latitude so every route snaps to the same cells.
$referenceLatitude = $campus["configured"] ? $campus["boundary"][0][0] : 10.7177;
if (!$campus["configured"]) {
    foreach ($routes as $route) {
        $referenceLatitude = $route["points"][0][0];
        break;
    }
}
$cellLat = ROUTE_CELL_METERS / METERS_PER_DEGREE_LATITUDE;
$cellLng = ROUTE_CELL_METERS / (METERS_PER_DEGREE_LATITUDE * max(0.01, cos(deg2rad($referenceLatitude))));

function route_nearest_gate(array $gates, array $point): ?int
{
    $nearestIndex = null;
    $nearestDistance = ROUTE_GATE_RADIUS_METERS;
    foreach ($gates as $index => $gate) {
        $distance = campus_distance_meters($point[0], $point[1], $gate["latitude"], $gate["longitude"]);
        if ($distance <= $nearestDistance) {
            $nearestIndex = $index;
            $nearestDistance = $distance;
        }
    }
    return $nearestIndex;
}

$segmentVisitors = [];
$destinationStats = [];
$gateEntries = array_fill(0, count($campus["gates"]), 0);
$gateExits = array_fill(0, count($campus["gates"]), 0);
$routeTotal = 0;
$distanceTotal = 0.0;
$pointsUsed = 0;

foreach ($routes as $route) {
    $points = $route["points"];
    if (count($points) < 2) {
        continue;
    }

    // Walked distance up to each point, so the walk to the office can be read off later.
    $walked = [0.0];
    $routeSegments = [];
    $previousCell = null;
    for ($index = 0; $index < count($points); $index++) {
        $point = $points[$index];
        $cell = [(int) floor($point[0] / $cellLat), (int) floor($point[1] / $cellLng)];
        if ($index > 0) {
            $before = $points[$index - 1];
            $step = campus_distance_meters($before[0], $before[1], $point[0], $point[1]);
            $walked[$index] = $walked[$index - 1] + ($step <= ROUTE_MAX_STEP_METERS ? $step : 0);
            if ($step <= ROUTE_MAX_STEP_METERS) {
                // Fill the cells between two readings so a fast walk still draws a continuous path.
                $rowDelta = $cell[0] - $previousCell[0];
                $colDelta = $cell[1] - $previousCell[1];
                $steps = max(abs($rowDelta), abs($colDelta));
                $last = $previousCell;
                for ($stepIndex = 1; $stepIndex <= $steps; $stepIndex++) {
                    $next = [
                        $previousCell[0] + (int) round($rowDelta * $stepIndex / $steps),
                        $previousCell[1] + (int) round($colDelta * $stepIndex / $steps),
                    ];
                    if ($next === $last) {
                        continue;
                    }
                    $pair = [$last[0] . ":" . $last[1], $next[0] . ":" . $next[1]];
                    sort($pair);
                    $routeSegments[$pair[0] . "|" . $pair[1]] = true;
                    $last = $next;
                }
            }
        }
        $previousCell = $cell;
    }

    if (!$routeSegments) {
        continue;
    }
    $distance = $walked[count($walked) - 1];

    $officeCode = $route["office_code"];
    if (!isset($destinationStats[$officeCode])) {
        $destinationStats[$officeCode] = [
            "routes" => 0, "distance" => 0.0,
            "arrivals" => 0, "walk_to_office" => 0.0, "direct" => 0.0, "seconds_to_office" => 0,
        ];
    }
    $stats = &$destinationStats[$officeCode];
    $stats["routes"]++;
    $stats["distance"] += $distance;
    if (isset($officePins[$officeCode])) {
        $pin = $officePins[$officeCode];
        foreach ($points as $index => $point) {
            if (campus_distance_meters($point[0], $point[1], $pin[0], $pin[1]) <= ROUTE_OFFICE_RADIUS_METERS) {
                $stats["arrivals"]++;
                $stats["walk_to_office"] += $walked[$index];
                $stats["direct"] += campus_distance_meters($points[0][0], $points[0][1], $pin[0], $pin[1]);
                $stats["seconds_to_office"] += max(0, $point[2] - $points[0][2]);
                break;
            }
        }
    }
    unset($stats);

    if ($office !== "" && $officeCode !== $office) {
        continue;
    }
    $routeTotal++;
    $distanceTotal += $distance;
    $pointsUsed += count($points);
    // Each visitor counts once per segment, however long they lingered on it.
    foreach (array_keys($routeSegments) as $key) {
        $segmentVisitors[$key] = ($segmentVisitors[$key] ?? 0) + 1;
    }
    $entryGate = route_nearest_gate($campus["gates"], $points[0]);
    if ($entryGate !== null) {
        $gateEntries[$entryGate]++;
    }
    $exitGate = route_nearest_gate($campus["gates"], $points[count($points) - 1]);
    if ($exitGate !== null) {
        $gateExits[$exitGate]++;
    }
}

arsort($segmentVisitors);
$maxVisitors = $segmentVisitors ? (int) reset($segmentVisitors) : 0;
$segments = [];
foreach (array_slice($segmentVisitors, 0, ROUTE_SEGMENT_LIMIT, true) as $key => $visitors) {
    $ends = [];
    foreach (explode("|", $key) as $cellKey) {
        [$row, $col] = array_map("intval", explode(":", $cellKey));
        $ends[] = [round(($row + 0.5) * $cellLat, 7), round(($col + 0.5) * $cellLng, 7)];
    }
    $segments[] = ["from" => $ends[0], "to" => $ends[1], "visitors" => (int) $visitors];
}

$destinations = [];
foreach ($destinationStats as $officeCode => $stats) {
    $arrivals = $stats["arrivals"];
    $destinations[] = [
        "code" => $officeCode,
        "label" => appointment_office_label((string) $officeCode),
        "routes" => $stats["routes"],
        "average_distance_meters" => (int) round($stats["distance"] / $stats["routes"]),
        "has_pin" => isset($officePins[$officeCode]),
        "arrivals" => $arrivals,
        "average_walk_to_office_meters" => $arrivals > 0 ? (int) round($stats["walk_to_office"] / $arrivals) : null,
        "average_direct_meters" => $arrivals > 0 ? (int) round($stats["direct"] / $arrivals) : null,
        "average_minutes_to_office" => $arrivals > 0 ? round($stats["seconds_to_office"] / $arrivals / 60, 1) : null,
    ];
}
usort($destinations, function (array $a, array $b): int {
    return $b["routes"] <=> $a["routes"];
});

$gates = [];
foreach ($campus["gates"] as $index => $gate) {
    $gates[] = ["name" => $gate["name"], "entries" => $gateEntries[$index], "exits" => $gateExits[$index]];
}

echo json_encode([
    "success" => true,
    "period" => $period,
    "office" => $office,
    "range" => ["start" => $startSql, "end" => $endSql],
    "campus_configured" => $campus["configured"],
    "summary" => [
        "routes" => $routeTotal,
        "average_distance_meters" => $routeTotal > 0 ? (int) round($distanceTotal / $routeTotal) : 0,
        "busiest_segment_visitors" => $maxVisitors,
        "points_used" => $pointsUsed,
        "points_outside_campus" => $pointsOutsideCampus,
    ],
    "segments" => $segments,
    "destinations" => $destinations,
    "gates" => $gates,
]);

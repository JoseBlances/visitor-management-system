<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/campus_map_service.php";

// Saves a walking route (new, or an edit of an existing one) from the Campus Map page.
require_permission_json("campus_map.edit");

const CAMPUS_ROUTE_MAX_POINTS = 2000;
const CAMPUS_ROUTE_MAX_LENGTH_METERS = 5000;
const CAMPUS_ROUTE_MAX_STEP_METERS = 500;
const CAMPUS_ROUTES_PER_OFFICE = 20;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

function route_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(["success" => false, "message" => $message]);
    exit;
}

if (!campus_routes_schema_ready($conn)) {
    route_fail(CAMPUS_ROUTES_MIGRATION_MESSAGE, 503);
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    route_fail("Invalid request.");
}

$campus = campus_map_load($conn);
if (!$campus["configured"]) {
    route_fail("Draw and save the campus boundary before adding routes.");
}

$routeId = isset($input["id"]) && $input["id"] !== null && $input["id"] !== "" ? (int) $input["id"] : null;
$existing = null;
if ($routeId !== null) {
    $found = campus_routes_load($conn, $routeId);
    if (!$found) {
        route_fail("That route no longer exists. Refresh the page.", 404);
    }
    $existing = $found[0];
}

$officeCode = strtoupper(trim((string) ($input["office_code"] ?? "")));
$offices = appointment_office_map();
if (!isset($offices[$officeCode])) {
    route_fail("Choose the department this route leads to.");
}

$startLabel = trim((string) ($input["start_label"] ?? ""));
if (mb_strlen($startLabel) > 100) {
    route_fail("The starting point's name can have at most 100 characters.");
}
$name = trim((string) ($input["name"] ?? ""));
if ($name === "") {
    $name = $startLabel !== "" ? $startLabel . " → " . $offices[$officeCode] : "Route to " . $offices[$officeCode];
}
if (mb_strlen($name) > 120) {
    route_fail("The route name can have at most 120 characters.");
}
$method = ($input["method"] ?? "") === "drawn" ? "drawn" : "walked";

$pointsInput = $input["points"] ?? null;
if (!is_array($pointsInput) || count($pointsInput) < 2) {
    route_fail("A route needs at least a start and an end point.");
}
if (count($pointsInput) > CAMPUS_ROUTE_MAX_POINTS) {
    route_fail("A route can have at most " . CAMPUS_ROUTE_MAX_POINTS . " points.");
}
$points = [];
foreach ($pointsInput as $index => $point) {
    if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])
        || abs((float) $point[0]) > 90 || abs((float) $point[1]) > 180) {
        route_fail("Point " . ((int) $index + 1) . " of the route is not a valid location.");
    }
    $latitude = round((float) $point[0], 7);
    $longitude = round((float) $point[1], 7);
    if (!campus_point_near_campus($campus["boundary"], $latitude, $longitude)) {
        route_fail("Point " . ((int) $index + 1) . " of the route is outside the campus. Routes must stay on campus (up to "
            . CAMPUS_EDGE_TOLERANCE_METERS . " m outside the boundary for gates).");
    }
    $previous = end($points);
    if ($previous !== false) {
        $step = campus_distance_meters($previous[0], $previous[1], $latitude, $longitude);
        if ($step < 0.05) {
            continue;
        }
        if ($step > CAMPUS_ROUTE_MAX_STEP_METERS) {
            route_fail("The route jumps " . round($step) . " m between two points. Record or draw it again.");
        }
    }
    $points[] = [$latitude, $longitude];
}
if (count($points) < 2) {
    route_fail("A route needs at least a start and an end point in different places.");
}
$distance = round(campus_path_length_meters($points), 1);
if ($distance < 2) {
    route_fail("The route is shorter than 2 m.");
}
if ($distance > CAMPUS_ROUTE_MAX_LENGTH_METERS) {
    route_fail("A route can be at most " . CAMPUS_ROUTE_MAX_LENGTH_METERS . " m long.");
}

$duration = isset($input["duration_seconds"]) && is_numeric($input["duration_seconds"])
    ? max(0, min(86400, (int) $input["duration_seconds"])) : null;
$accuracy = isset($input["average_accuracy_meters"]) && is_numeric($input["average_accuracy_meters"])
    ? round(max(0, min(999, (float) $input["average_accuracy_meters"])), 1) : null;
if ($method === "drawn") {
    $duration = null;
    $accuracy = null;
}

if ($existing === null) {
    $count = $conn->prepare("SELECT COUNT(*) FROM campus_routes WHERE office_code = ?");
    $count->bind_param("s", $officeCode);
    $count->execute();
    $perOffice = (int) $count->get_result()->fetch_row()[0];
    $count->close();
    if ($perOffice >= CAMPUS_ROUTES_PER_OFFICE) {
        route_fail($offices[$officeCode] . " already has " . CAMPUS_ROUTES_PER_OFFICE . " routes. Delete one first.");
    }
}

$userId = (int) $_SESSION["user_id"];
$pointsJson = json_encode($points);
$pointCount = count($points);
if ($existing === null) {
    $stmt = $conn->prepare(
        "INSERT INTO campus_routes
         (office_code, name, start_label, method, points_json, point_count, distance_meters, duration_seconds,
          average_accuracy_meters, created_by_user_id, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssssididii", $officeCode, $name, $startLabel, $method, $pointsJson, $pointCount, $distance,
        $duration, $accuracy, $userId, $userId);
} else {
    $stmt = $conn->prepare(
        "UPDATE campus_routes
         SET office_code = ?, name = ?, start_label = ?, method = ?, points_json = ?, point_count = ?,
             distance_meters = ?, duration_seconds = ?, average_accuracy_meters = ?, updated_by_user_id = ?
         WHERE id = ?"
    );
    $stmt->bind_param("sssssididii", $officeCode, $name, $startLabel, $method, $pointsJson, $pointCount, $distance,
        $duration, $accuracy, $userId, $routeId);
}
$stmt->execute();
$savedId = $existing === null ? (int) $stmt->insert_id : (int) $routeId;
$stmt->close();

auth_audit($conn, $userId, $existing === null ? "campus.route_saved" : "campus.route_updated", "campus_route", (string) $savedId, [
    "name" => $name,
    "office_code" => $officeCode,
    "distance_meters" => $distance,
    "method" => $method,
]);

$saved = campus_routes_load($conn, $savedId);
$conn->close();

echo json_encode(["success" => true, "route" => $saved[0] ?? null], JSON_UNESCAPED_UNICODE);

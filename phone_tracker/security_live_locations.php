<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";

require_permission_json("locations.view_live");
refresh_appointment_time_states($conn);

// A multi-stop visit is one visitor on campus: it appears once, under the stop that
// owns its tracking session, and stays live until the whole visit ends.
$visitReady = visit_schema_ready($conn);
$activeCondition = $visitReady
    ? "(a.status = 'checked_in' AND a.visit_id IS NULL)
       OR a.id IN (SELECT tracking_appointment_id FROM visits
                   WHERE status = 'checked_in' AND tracking_appointment_id IS NOT NULL)"
    : "a.status = 'checked_in'";
$visitColumns = visit_appointment_columns($conn, "a");

$sql =
    "SELECT a.id AS appointment_id, a.public_token, a.visitor_full_name,
            a.office_code, a.device_name, a.checked_in_at{$visitColumns},
            l.latitude, l.longitude, l.accuracy, l.recorded_at
     FROM appointments a
     LEFT JOIN locations l ON l.id = (
         SELECT l2.id
         FROM locations l2
         WHERE l2.appointment_id = a.id
           AND l2.recorded_at >= a.checked_in_at
         ORDER BY l2.recorded_at DESC, l2.id DESC
         LIMIT 1
     )
     WHERE {$activeCondition}
     ORDER BY a.checked_in_at DESC, a.id DESC";

$rows = [];
$officeMap = appointment_office_map();
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $officeCode = (string) ($row["office_code"] ?? "");
        $row["appointment_id"] = (int) $row["appointment_id"];
        $row["office_label"] = !empty($row["visit_id"])
            ? visit_route_label(visit_stops($conn, (int) $row["visit_id"]))
            : ($officeMap[$officeCode] ?? $officeCode);
        $row["has_location"] = $row["latitude"] !== null && $row["longitude"] !== null;
        $row["location_state"] = "waiting";
        $row["seconds_since_update"] = null;
        if ($row["has_location"]) {
            $row["latitude"] = (float) $row["latitude"];
            $row["longitude"] = (float) $row["longitude"];
            $row["accuracy"] = $row["accuracy"] === null ? null : (float) $row["accuracy"];
            $recordedAt = strtotime((string) $row["recorded_at"]);
            $age = $recordedAt ? max(0, time() - $recordedAt) : null;
            $row["seconds_since_update"] = $age;
            $row["location_state"] = $age === null ? "offline" : ($age <= 45 ? "live" : ($age <= 180 ? "stale" : "offline"));
        }
        $rows[] = $row;
    }
}

if ($visitReady) {
    $visitJoin = "LEFT JOIN visits v ON v.tracking_appointment_id = a.id";
    $statusExpression = "CASE WHEN v.id IS NULL THEN a.status WHEN v.status = 'checked_in' THEN 'checked_in' ELSE 'completed' END";
    $completedExpression = "CASE WHEN v.id IS NULL THEN a.completed_at ELSE v.completed_at END";
    $visitGroup = ", a.visit_id, v.id, v.status, v.completed_at";
} else {
    $visitJoin = "";
    $statusExpression = "a.status";
    $completedExpression = "a.completed_at";
    $visitGroup = ", a.visit_id";
}
$routeVisits = [];
$routeResult = $conn->query(
    "SELECT a.id AS appointment_id, a.visitor_full_name, a.office_code, a.device_name,
            {$statusExpression} AS status, a.checked_in_at, {$completedExpression} AS completed_at{$visitColumns},
            COUNT(l.id) AS point_count, MAX(l.recorded_at) AS last_location_at
     FROM appointments a
     {$visitJoin}
     INNER JOIN locations l ON l.appointment_id = a.id
       AND l.recorded_at >= a.checked_in_at
       AND ({$completedExpression} IS NULL OR l.recorded_at <= {$completedExpression})
     WHERE {$statusExpression} IN ('checked_in', 'completed')
       AND a.checked_in_at IS NOT NULL
     GROUP BY a.id, a.visitor_full_name, a.office_code, a.device_name,
              a.status, a.checked_in_at, a.completed_at{$visitGroup}
     ORDER BY COALESCE({$completedExpression}, a.checked_in_at) DESC, a.id DESC
     LIMIT 100"
);
if ($routeResult) {
    while ($route = $routeResult->fetch_assoc()) {
        $officeCode = (string) ($route["office_code"] ?? "");
        $route["appointment_id"] = (int) $route["appointment_id"];
        $route["point_count"] = (int) $route["point_count"];
        $route["office_label"] = !empty($route["visit_id"])
            ? visit_route_label(visit_stops($conn, (int) $route["visit_id"]))
            : ($officeMap[$officeCode] ?? $officeCode);
        $routeVisits[] = $route;
    }
}

$conn->close();

echo json_encode([
    "success" => true,
    "data" => $rows,
    "route_visits" => $routeVisits,
]);

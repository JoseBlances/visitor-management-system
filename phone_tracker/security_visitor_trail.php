<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/monitoring_service.php";

// A visitor's walked path inside the campus, from check-in to now (or to check-out).
// ?appointment_id= any stop of the visit; &after_id= only newer points, for live updates.

require_permission_json("routes.view");

$appointmentId = (int) ($_GET["appointment_id"] ?? 0);
$afterId = max(0, (int) ($_GET["after_id"] ?? 0));
if ($appointmentId <= 0) {
    http_response_code(422);
    echo json_encode(["success" => false, "message" => "Select a visitor."]);
    exit;
}

$visitor = monitor_visitor($conn, $appointmentId);
if (!$visitor) {
    $conn->close();
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "This visitor has not been checked in, so there is no route to show."]);
    exit;
}
$trail = monitor_trail($conn, $visitor, $afterId);
$conn->close();

echo json_encode(array_merge([
    "success" => true,
    "server_time" => date("Y-m-d H:i:s"),
    "rules" => monitor_rules(),
    "visitor" => $visitor,
], $trail));

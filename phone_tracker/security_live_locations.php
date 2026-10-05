<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/monitoring_service.php";

// The Security live map: visitors on campus with their latest position inside the
// campus and direction of travel, plus the visits that ended today. A multi-office visit
// is one entry, identified by the stop that owns its tracking session.

require_permission_json("locations.view_live");
refresh_appointment_time_states($conn);

$campus = presence_campus($conn);
$response = [
    "success" => true,
    "server_time" => date("Y-m-d H:i:s"),
    "campus_configured" => (bool) $campus["configured"],
    "privacy_filter" => presence_schema_ready($conn),
    "rules" => monitor_rules(),
    "data" => monitor_visitors($conn, "active"),
    "finished" => monitor_visitors($conn, "finished"),
];
$conn->close();

echo json_encode($response);

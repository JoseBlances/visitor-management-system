<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_visits.php";

api_require_method("GET");
$user = api_require_visitor();
if (!visit_schema_ready($conn)) {
    api_fail("Multi-stop visits require multi_stop_visits_migration.sql", 503);
}
refresh_appointment_time_states($conn);
$visitId = max(0, (int) ($_GET["id"] ?? 0));
if ($visitId <= 0) {
    api_fail("Select a valid visit", 422);
}
$visit = mobile_owned_visit($conn, $visitId, (int) $user["id"]);
if (!$visit) {
    api_fail("Visit not found", 404);
}
api_success(["visit" => mobile_visit_payload($conn, $visit)]);

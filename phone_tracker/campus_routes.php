<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/campus_map_service.php";

// Walking routes from a start (usually a gate) to each department, recorded on the Campus
// Map page. Staff who can see the campus map can read them; only admins change them.
require_permission_json("campus_map.view");

if (!campus_routes_schema_ready($conn)) {
    $conn->close();
    echo json_encode(["success" => false, "code" => "migration_required", "message" => CAMPUS_ROUTES_MIGRATION_MESSAGE]);
    exit;
}

$routes = campus_routes_load($conn);
$conn->close();

echo json_encode([
    "success" => true,
    "routes" => $routes,
    "can_edit" => auth_role_can((string) $_SESSION["role"], "campus_map.edit"),
], JSON_UNESCAPED_UNICODE);

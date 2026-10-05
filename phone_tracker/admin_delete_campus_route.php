<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/campus_map_service.php";

// Deletes one walking route from the Campus Map page.
require_permission_json("campus_map.edit");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}
if (!campus_routes_schema_ready($conn)) {
    http_response_code(503);
    echo json_encode(["success" => false, "message" => CAMPUS_ROUTES_MIGRATION_MESSAGE]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$routeId = is_array($input) && isset($input["id"]) ? (int) $input["id"] : 0;
$found = $routeId > 0 ? campus_routes_load($conn, $routeId) : [];
if (!$found) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "That route no longer exists. Refresh the page."]);
    exit;
}
$route = $found[0];

$stmt = $conn->prepare("DELETE FROM campus_routes WHERE id = ?");
$stmt->bind_param("i", $routeId);
$stmt->execute();
$stmt->close();

auth_audit($conn, (int) $_SESSION["user_id"], "campus.route_deleted", "campus_route", (string) $routeId, [
    "name" => $route["name"],
    "office_code" => $route["office_code"],
    "distance_meters" => $route["distance_meters"],
]);
$conn->close();

echo json_encode(["success" => true]);

<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/campus_map_service.php";

require_roles_json(["admin", "security"]);

$campus = campus_map_load($conn);
$conn->close();

echo json_encode([
    "success" => true,
    "campus" => $campus,
]);

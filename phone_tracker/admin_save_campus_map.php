<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/campus_map_service.php";

require_permission_json("campus_map.edit");

const CAMPUS_GATE_TOLERANCE_METERS = 40;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid body"]);
    exit;
}

function campus_fail(string $message): void
{
    echo json_encode(["success" => false, "message" => $message]);
    exit;
}

/**
 * @return array{0: float, 1: float}
 */
function campus_read_point($latitude, $longitude, string $what): array
{
    if (!is_numeric($latitude) || !is_numeric($longitude)
        || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
        campus_fail("Invalid coordinates for " . $what . ".");
    }
    return [round((float) $latitude, 7), round((float) $longitude, 7)];
}

$boundaryInput = isset($input["boundary"]) && is_array($input["boundary"]) ? $input["boundary"] : [];
$gatesInput = isset($input["gates"]) && is_array($input["gates"]) ? $input["gates"] : [];
$officesInput = isset($input["offices"]) && is_array($input["offices"]) ? $input["offices"] : [];

if (count($boundaryInput) < 3 || count($boundaryInput) > 200) {
    campus_fail("Draw the campus boundary with at least 3 corners (at most 200).");
}
if (count($gatesInput) > 20) {
    campus_fail("A campus can have at most 20 gates.");
}

$boundary = [];
foreach ($boundaryInput as $index => $corner) {
    if (!is_array($corner) || count($corner) < 2) {
        campus_fail("Invalid boundary corner.");
    }
    $boundary[] = campus_read_point($corner[0], $corner[1], "boundary corner " . ((int) $index + 1));
}

$gates = [];
$gateNames = [];
foreach ($gatesInput as $gate) {
    $name = is_array($gate) ? trim((string) ($gate["name"] ?? "")) : "";
    if ($name === "" || mb_strlen($name) > 100) {
        campus_fail("Each gate needs a name of up to 100 characters.");
    }
    if (isset($gateNames[mb_strtolower($name)])) {
        campus_fail("Gate names must be unique: " . $name);
    }
    $gateNames[mb_strtolower($name)] = true;
    $point = campus_read_point($gate["latitude"] ?? null, $gate["longitude"] ?? null, $name);
    $gates[] = ["name" => $name, "point" => $point];
}

$officeMap = appointment_office_map();
$offices = [];
foreach ($officesInput as $office) {
    $code = is_array($office) ? strtoupper(trim((string) ($office["code"] ?? ""))) : "";
    if (!isset($officeMap[$code])) {
        campus_fail("Unknown office in office pins.");
    }
    if (isset($offices[$code])) {
        campus_fail("Each office can only have one pin.");
    }
    $offices[$code] = campus_read_point($office["latitude"] ?? null, $office["longitude"] ?? null, $officeMap[$code]);
}

foreach ($gates as $gate) {
    // Gates sit on the campus edge, so allow them a little outside the drawn line.
    if (!campus_contains($boundary, $gate["point"][0], $gate["point"][1])
        && campus_distance_to_boundary_meters($boundary, $gate["point"][0], $gate["point"][1]) > CAMPUS_GATE_TOLERANCE_METERS) {
        campus_fail($gate["name"] . " must be on or inside the campus boundary.");
    }
}
foreach ($offices as $code => $point) {
    if (!campus_contains($boundary, $point[0], $point[1])) {
        campus_fail($officeMap[$code] . " must be placed inside the campus boundary.");
    }
}

if (!campus_map_ensure_table($conn)) {
    campus_fail("Could not prepare the campus map table.");
}

$userId = (int) $_SESSION["user_id"];
$conn->begin_transaction();
try {
    $conn->query("DELETE FROM campus_places");
    $stmt = $conn->prepare(
        "INSERT INTO campus_places (place_type, office_code, name, latitude, longitude, sort_order, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $insert = function (string $type, ?string $officeCode, string $name, array $point, int $order) use ($stmt, $userId): void {
        $stmt->bind_param("sssddii", $type, $officeCode, $name, $point[0], $point[1], $order, $userId);
        if (!$stmt->execute()) {
            throw new RuntimeException("Insert failed");
        }
    };
    foreach ($boundary as $index => $point) {
        $insert("boundary", null, "Campus boundary", $point, $index);
    }
    foreach ($gates as $index => $gate) {
        $insert("gate", null, $gate["name"], $gate["point"], $index);
    }
    $order = 0;
    foreach ($offices as $code => $point) {
        $insert("office", $code, $officeMap[$code], $point, $order++);
    }
    $stmt->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    campus_fail("Could not save the campus map.");
}

$campus = campus_map_load($conn);
$conn->close();

echo json_encode(["success" => true, "campus" => $campus]);

<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/presence_service.php";

// Security's "End visit" button. The usual way out is the second scan of the visitor
// pass (scan_checkout.php); this covers a visitor who lost their phone or pass.

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_permission_json("visits.complete");

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input) || !isset($input["id"])) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

$appointmentId = (int) $input["id"];
$securityUserId = (int) $_SESSION["user_id"];
if ($appointmentId <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid appointment"]);
    exit;
}

// Ending any stop of a multi-stop visit is a gate checkout for the whole visit.
try {
    $ended = gate_checkout($conn, $appointmentId, $securityUserId, "guard");
} catch (Throwable $error) {
    error_log("End visit failed for appointment {$appointmentId}: " . $error->getMessage());
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not end the visit"]);
    exit;
}
$conn->close();

if (!$ended) {
    echo json_encode(["success" => false, "message" => "Visit is not active or already completed"]);
    exit;
}
echo json_encode(["success" => true, "message" => "Visit marked as complete", "completed_at" => $ended["completed_at"]]);

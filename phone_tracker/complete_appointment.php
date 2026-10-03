<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/visit_service.php";

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
$visit = visit_for_appointment($conn, $appointmentId);
if ($visit) {
    $conn->begin_transaction();
    try {
        $locked = visit_load($conn, (int) $visit["id"], true);
        if (!$locked || $locked["status"] !== "checked_in") {
            throw new DomainException("Visit is not active or already completed");
        }
        visit_complete($conn, $locked, $securityUserId, "Visit ended by security", null, true);
        $conn->commit();
    } catch (DomainException $error) {
        $conn->rollback();
        $conn->close();
        echo json_encode(["success" => false, "message" => $error->getMessage()]);
        exit;
    } catch (Throwable $error) {
        $conn->rollback();
        $conn->close();
        echo json_encode(["success" => false, "message" => "Could not end the visit"]);
        exit;
    }
    $conn->close();
    echo json_encode(["success" => true, "message" => "Visit marked as complete"]);
    exit;
}

// Backward compatible: if migration columns are missing, fallback query still works.
$stmt = $conn->prepare(
    "UPDATE appointments SET status = 'completed', status_updated_at = NOW(), completed_at = NOW(), completed_by_user_id = ? WHERE id = ? AND status = 'checked_in' LIMIT 1"
);
$usedExtendedUpdate = true;
if (!$stmt) {
    $usedExtendedUpdate = false;
    $stmt = $conn->prepare(
        "UPDATE appointments SET status = 'completed' WHERE id = ? AND status = 'checked_in' LIMIT 1"
    );
}
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

if ($usedExtendedUpdate) {
    $stmt->bind_param("ii", $securityUserId, $appointmentId);
} else {
    $stmt->bind_param("i", $appointmentId);
}
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected < 1) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Visit is not active or already completed"]);
    exit;
}

$hist = $conn->prepare(
    "INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, note) VALUES (?, 'checked_in', 'completed', ?, 'Visit ended by security')"
);
if ($hist) {
    $hist->bind_param("ii", $appointmentId, $securityUserId);
    $hist->execute();
    $hist->close();
}

// Phase 4 mobile tracking sessions are optional on older installations. When the
// migration is present, completion immediately closes the app's active session.
$tracking = false;
try {
    $tracking = $conn->prepare(
        "UPDATE location_tracking_sessions
         SET ended_at = COALESCE(ended_at, NOW()), ended_reason = 'completed'
         WHERE appointment_id = ? AND ended_at IS NULL"
    );
} catch (Throwable $ignored) {
    // Preserve the legacy web workflow until mobile_api_migration.sql is installed.
}
if ($tracking) {
    $tracking->bind_param("i", $appointmentId);
    $tracking->execute();
    $tracking->close();
}

$visitorLookup = $conn->prepare("SELECT visitor_user_id FROM appointments WHERE id = ? LIMIT 1");
if ($visitorLookup) {
    $visitorLookup->bind_param("i", $appointmentId);
    $visitorLookup->execute();
    $visitorRow = $visitorLookup->get_result()->fetch_assoc();
    $visitorLookup->close();
    if ($visitorRow) {
        $visitorUserId = (int) $visitorRow["visitor_user_id"];
        $notification = $conn->prepare(
            "INSERT INTO app_notifications
             (recipient_user_id, appointment_id, notification_type, title, message)
             VALUES (?, ?, 'appointment.completed', 'Visit completed', 'Security completed your campus visit. Location tracking has stopped.')"
        );
        if ($notification) {
            $notification->bind_param("ii", $visitorUserId, $appointmentId);
            $notification->execute();
            $notification->close();
        }
    }
}

$audit = $conn->prepare(
    "INSERT INTO audit_logs
     (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
     VALUES (?, ?, 'appointment.completed', 'appointment', ?, '{}', ?)"
);
if ($audit) {
    $entityId = (string) $appointmentId;
    $ipAddress = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
    $audit->bind_param("iiss", $securityUserId, $appointmentId, $entityId, $ipAddress);
    $audit->execute();
    $audit->close();
}
$conn->close();

echo json_encode(["success" => true, "message" => "Visit marked as complete"]);

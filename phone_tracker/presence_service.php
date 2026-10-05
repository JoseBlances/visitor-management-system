<?php
/*
 * Campus presence and gate check-out.
 *
 * - Every GPS reading is classified as inside or outside the campus boundary. Positions
 *   outside are never stored; visitor_presence only keeps the time a visitor stepped out.
 * - A visit ends in one of four ways (appointments.checkout_method / visits.checkout_method):
 *     scan        Security scanned the pass a second time at the gate
 *     guard       Security pressed End visit
 *     left_campus the visitor stayed outside the boundary (confirmed exit)
 *     end_of_day  the visit was still open when its day ended
 *
 * See LIVE_MONITORING.md for the rules in plain language.
 */

require_once __DIR__ . "/campus_map_service.php";
require_once __DIR__ . "/visit_service.php";

// A reading this far outside the boundary still counts as inside, so GPS jitter at the
// gates does not flip a visitor in and out.
const PRESENCE_EDGE_TOLERANCE_METERS = 25;
// Readings less accurate than this neither move a visitor on the map nor change their
// inside/outside state (the same limit as the admin's route analytics).
const PRESENCE_MAX_ACCURACY_METERS = 50;
// A campus exit is confirmed after this long outside, with at least this many accurate
// readings outside (MAP_GEOFENCE_HANDOFF.md asks for three).
const PRESENCE_EXIT_CONFIRM_SECONDS = 300;
const PRESENCE_EXIT_MIN_READINGS = 3;
// A second scan this soon after check-in is a repeated scan, never a check-out.
const GATE_RESCAN_GRACE_SECONDS = 120;
// The dashboard shows "Overstay" this long after the booked slot ended.
const GATE_OVERSTAY_GRACE_MINUTES = 30;

const GATE_CHECKOUT_METHODS = ["scan", "guard", "left_campus", "end_of_day"];
const PRESENCE_MIGRATION_MESSAGE = "Database update required: import phone_tracker/live_monitoring_migration.sql into phone_tracker.";

/** True once live_monitoring_migration.sql has been imported. */
function presence_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $table = $conn->query("SHOW TABLES LIKE 'visitor_presence'");
        $column = $conn->query("SHOW COLUMNS FROM appointments LIKE 'checkout_method'");
        $ready = $table && $table->num_rows > 0 && $column && $column->num_rows > 0;
    } catch (Throwable $ignored) {
        $ready = false;
    }
    return $ready;
}

/** The campus map, loaded once per request. */
function presence_campus(mysqli $conn): array
{
    static $campus = null;
    if ($campus === null) {
        $campus = campus_map_load($conn);
    }
    return $campus;
}

/**
 * True when a position counts as inside the campus. Without a saved boundary every
 * position counts as inside, so nothing is hidden by mistake.
 */
function presence_is_inside(array $campus, float $latitude, float $longitude): bool
{
    if (empty($campus["configured"])) {
        return true;
    }
    return campus_contains($campus["boundary"], $latitude, $longitude)
        || campus_distance_to_boundary_meters($campus["boundary"], $latitude, $longitude) <= PRESENCE_EDGE_TOLERANCE_METERS;
}

/** True when a reading is accurate enough to place a visitor or change their state. */
function presence_is_accurate(mixed $accuracy): bool
{
    return $accuracy === null || (float) $accuracy <= PRESENCE_MAX_ACCURACY_METERS;
}

function presence_load(mysqli $conn, int $appointmentId): ?array
{
    if (!presence_schema_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM visitor_presence WHERE appointment_id = ? LIMIT 1");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Updates a visitor's inside/outside state from new readings. $appointmentId is the
 * appointment that owns the tracking session. Each reading is
 * ["captured_at" => "Y-m-d H:i:s", "inside" => bool, "accuracy" => ?float].
 * Readings older than the newest one already processed are ignored, so re-sent and
 * late offline batches cannot move the state backwards. Returns the updated row.
 */
function presence_record(mysqli $conn, int $appointmentId, array $readings): ?array
{
    if (!$readings || !presence_schema_ready($conn)) {
        return null;
    }
    usort($readings, function (array $a, array $b): int {
        return strcmp((string) $a["captured_at"], (string) $b["captured_at"]);
    });

    $ensure = $conn->prepare("INSERT IGNORE INTO visitor_presence (appointment_id) VALUES (?)");
    $ensure->bind_param("i", $appointmentId);
    $ensure->execute();
    $ensure->close();
    $load = $conn->prepare("SELECT * FROM visitor_presence WHERE appointment_id = ? LIMIT 1 FOR UPDATE");
    $load->bind_param("i", $appointmentId);
    $load->execute();
    $row = $load->get_result()->fetch_assoc();
    $load->close();
    if (!$row) {
        return null;
    }

    $events = [];
    foreach ($readings as $reading) {
        $capturedAt = (string) $reading["captured_at"];
        if ($row["last_report_at"] !== null && $capturedAt <= (string) $row["last_report_at"]) {
            continue;
        }
        $row["last_report_at"] = $capturedAt;
        if (!presence_is_accurate($reading["accuracy"] ?? null)) {
            continue;
        }
        if (!empty($reading["inside"])) {
            if ($row["state"] === "outside") {
                $events[] = ["presence.returned_to_campus", ["at" => $capturedAt, "outside_since" => $row["outside_since"]]];
            }
            $row["state"] = "inside";
            $row["outside_since"] = null;
            $row["outside_readings"] = 0;
            $row["last_inside_at"] = $capturedAt;
        } elseif ($row["state"] !== "outside") {
            $row["state"] = "outside";
            $row["outside_since"] = $capturedAt;
            $row["outside_readings"] = 1;
            $events[] = ["presence.left_campus", ["at" => $capturedAt]];
        } else {
            $row["outside_readings"] = (int) $row["outside_readings"] + 1;
        }
    }

    $update = $conn->prepare(
        "UPDATE visitor_presence
         SET state = ?, outside_since = ?, outside_readings = ?, last_report_at = ?, last_inside_at = ?
         WHERE appointment_id = ?"
    );
    $state = (string) $row["state"];
    $outsideSince = $row["outside_since"];
    $outsideReadings = (int) $row["outside_readings"];
    $lastReport = $row["last_report_at"];
    $lastInside = $row["last_inside_at"];
    $update->bind_param("ssissi", $state, $outsideSince, $outsideReadings, $lastReport, $lastInside, $appointmentId);
    $update->execute();
    $update->close();

    foreach ($events as [$action, $details]) {
        presence_audit($conn, null, $appointmentId, $action, $details);
    }
    return $row;
}

/** True when a visitor has been outside long enough, with enough readings, to have left. */
function presence_exit_confirmed(?array $row, ?int $now = null): bool
{
    if (!$row || $row["state"] !== "outside" || $row["outside_since"] === null) {
        return false;
    }
    $now = $now ?? time();
    return (int) $row["outside_readings"] >= PRESENCE_EXIT_MIN_READINGS
        && $now - strtotime((string) $row["outside_since"]) >= PRESENCE_EXIT_CONFIRM_SECONDS;
}

function presence_audit(mysqli $conn, ?int $actorUserId, int $appointmentId, string $action, array $details): void
{
    $audit = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, ?, 'appointment', ?, ?, ?)"
    );
    if (!$audit) {
        return;
    }
    $entityId = (string) $appointmentId;
    $detailsJson = json_encode($details);
    // Automatic events have no network address (the column does not allow NULL).
    $ip = $actorUserId ? substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45) : "";
    $audit->bind_param("iissss", $actorUserId, $appointmentId, $action, $entityId, $detailsJson, $ip);
    $audit->execute();
    $audit->close();
}

/* ---------- Ending a visit ---------- */

function gate_checkout_note(string $method): string
{
    return [
        "scan" => "Checked out at the gate (second scan of the visitor pass)",
        "guard" => "Visit ended by security",
        "left_campus" => "Visitor left the campus without checking out",
        "end_of_day" => "Visit was still open at the end of the day",
    ][$method] ?? "Visit ended";
}

/** The notification the visitor receives when their visit ends. */
function gate_checkout_message(string $method): string
{
    return [
        "scan" => "Security checked you out at the gate. Location tracking has stopped. Thank you for visiting ISATU.",
        "guard" => "Security completed your campus visit. Location tracking has stopped.",
        "left_campus" => "You left the campus, so your visit has ended and location tracking has stopped.",
        "end_of_day" => "Your visit was still open at the end of the day, so it ended automatically. Location tracking has stopped.",
    ][$method] ?? "Your campus visit is complete. Location tracking has stopped.";
}

/**
 * Ends a checked-in visit. $appointmentId may be a single appointment or any stop of a
 * multi-office visit (which ends the whole visit and cancels stops not yet visited).
 * Starts and commits its own transaction. Returns null when the visit is not checked in.
 *
 * @return array{appointment_id:int, visit_id:?int, completed_at:string, method:string}|null
 */
function gate_checkout(mysqli $conn, int $appointmentId, ?int $actorUserId, string $method, ?string $completedAt = null): ?array
{
    if (!in_array($method, GATE_CHECKOUT_METHODS, true)) {
        throw new InvalidArgumentException("Unknown check-out method");
    }
    $completedAt = $completedAt ?: date("Y-m-d H:i:s");
    $note = gate_checkout_note($method);
    $recordMethod = presence_schema_ready($conn);

    $conn->begin_transaction();
    try {
        $visit = visit_for_appointment($conn, $appointmentId);
        if ($visit) {
            $locked = visit_load($conn, (int) $visit["id"], true);
            if (!$locked || $locked["status"] !== "checked_in") {
                $conn->rollback();
                return null;
            }
            visit_complete($conn, $locked, $actorUserId, $note, $completedAt, true, $method);
            if ($recordMethod) {
                $mark = $conn->prepare("UPDATE visits SET checkout_method = ? WHERE id = ?");
                $visitId = (int) $locked["id"];
                $mark->bind_param("si", $method, $visitId);
                $mark->execute();
                $mark->close();
            }
            $conn->commit();
            return [
                "appointment_id" => !empty($locked["tracking_appointment_id"]) ? (int) $locked["tracking_appointment_id"] : $appointmentId,
                "visit_id" => (int) $locked["id"],
                "completed_at" => $completedAt,
                "method" => $method,
            ];
        }

        $methodSet = $recordMethod ? ", checkout_method = ?" : "";
        $update = $conn->prepare(
            "UPDATE appointments
             SET status = 'completed', status_updated_at = NOW(), completed_at = ?, completed_by_user_id = ?{$methodSet}
             WHERE id = ? AND status = 'checked_in'"
        );
        if ($recordMethod) {
            $update->bind_param("sisi", $completedAt, $actorUserId, $method, $appointmentId);
        } else {
            $update->bind_param("sii", $completedAt, $actorUserId, $appointmentId);
        }
        $update->execute();
        $changed = $update->affected_rows;
        $update->close();
        if ($changed !== 1) {
            $conn->rollback();
            return null;
        }

        $history = $conn->prepare(
            "INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, note)
             VALUES (?, 'checked_in', 'completed', ?, ?)"
        );
        $history->bind_param("iis", $appointmentId, $actorUserId, $note);
        $history->execute();
        $history->close();

        try {
            $endedReason = $method === "left_campus" ? "campus_exit" : "completed";
            $tracking = $conn->prepare(
                "UPDATE location_tracking_sessions SET ended_at = COALESCE(ended_at, ?), ended_reason = ?
                 WHERE appointment_id = ? AND ended_at IS NULL"
            );
            $tracking->bind_param("ssi", $completedAt, $endedReason, $appointmentId);
            $tracking->execute();
            $tracking->close();
        } catch (mysqli_sql_exception $ignored) {
            // mobile_api_migration.sql is not installed; there are no app tracking sessions.
        }

        $visitor = $conn->prepare("SELECT visitor_user_id FROM appointments WHERE id = ? LIMIT 1");
        $visitor->bind_param("i", $appointmentId);
        $visitor->execute();
        $visitorUserId = (int) ($visitor->get_result()->fetch_assoc()["visitor_user_id"] ?? 0);
        $visitor->close();
        if ($visitorUserId > 0) {
            $notification = $conn->prepare(
                "INSERT INTO app_notifications (recipient_user_id, appointment_id, notification_type, title, message)
                 VALUES (?, ?, 'appointment.completed', 'Visit completed', ?)"
            );
            $message = gate_checkout_message($method);
            $notification->bind_param("iis", $visitorUserId, $appointmentId, $message);
            $notification->execute();
            $notification->close();
        }

        presence_audit($conn, $actorUserId, $appointmentId, "appointment.completed", [
            "method" => $method,
            "completed_at" => $completedAt,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return ["appointment_id" => $appointmentId, "visit_id" => null, "completed_at" => $completedAt, "method" => $method];
}

/* ---------- Automatic endings (run from refresh_appointment_time_states) ---------- */

/** Ends visits whose visitor has confirmed a campus exit, at the moment they stepped out. */
function refresh_presence_exits(mysqli $conn): void
{
    if (!presence_schema_ready($conn)) {
        return;
    }
    $stillOnCampus = visit_schema_ready($conn)
        ? "((a.visit_id IS NULL AND a.status = 'checked_in')
            OR EXISTS (SELECT 1 FROM visits v WHERE v.tracking_appointment_id = a.id AND v.status = 'checked_in'))"
        : "a.status = 'checked_in'";
    $stmt = $conn->prepare(
        "SELECT p.appointment_id, p.outside_since
         FROM visitor_presence p
         INNER JOIN appointments a ON a.id = p.appointment_id
         WHERE p.state = 'outside' AND p.outside_readings >= ?
           AND p.outside_since <= NOW() - INTERVAL ? SECOND
           AND {$stillOnCampus}
         ORDER BY p.outside_since ASC
         LIMIT 50"
    );
    $minReadings = PRESENCE_EXIT_MIN_READINGS;
    $confirmSeconds = PRESENCE_EXIT_CONFIRM_SECONDS;
    $stmt->bind_param("ii", $minReadings, $confirmSeconds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $row) {
        try {
            gate_checkout($conn, (int) $row["appointment_id"], null, "left_campus", (string) $row["outside_since"]);
        } catch (Throwable $error) {
            error_log("Automatic campus-exit check-out failed for appointment " . $row["appointment_id"] . ": " . $error->getMessage());
        }
    }
}

/**
 * Ends visits still open after their day: at 23:59:59 on the day of check-in, or at the
 * scheduled end if that is later (a walk-in that runs past midnight).
 */
function refresh_checked_in_end_of_day(mysqli $conn): void
{
    $deadlines = [];
    $singleCondition = visit_schema_ready($conn) ? "AND visit_id IS NULL" : "";
    $result = $conn->query(
        "SELECT id, GREATEST(TIMESTAMP(DATE(checked_in_at), '23:59:59'), COALESCE(scheduled_end_at, checked_in_at)) AS deadline
         FROM appointments
         WHERE status = 'checked_in' AND checked_in_at IS NOT NULL {$singleCondition}
         HAVING deadline < NOW()
         ORDER BY deadline ASC
         LIMIT 50"
    );
    if ($result) {
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $deadlines[] = [(int) $row["id"], (string) $row["deadline"]];
        }
    }
    if (visit_schema_ready($conn)) {
        $result = $conn->query(
            "SELECT v.id, COALESCE(v.tracking_appointment_id, MIN(a.id)) AS appointment_id,
                    GREATEST(TIMESTAMP(DATE(v.checked_in_at), '23:59:59'), COALESCE(MAX(a.scheduled_end_at), v.checked_in_at)) AS deadline
             FROM visits v
             INNER JOIN appointments a ON a.visit_id = v.id
             WHERE v.status = 'checked_in' AND v.checked_in_at IS NOT NULL
             GROUP BY v.id, v.tracking_appointment_id, v.checked_in_at
             HAVING deadline < NOW()
             ORDER BY deadline ASC
             LIMIT 50"
        );
        if ($result) {
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $deadlines[] = [(int) $row["appointment_id"], (string) $row["deadline"]];
            }
        }
    }
    foreach ($deadlines as [$appointmentId, $deadline]) {
        try {
            gate_checkout($conn, $appointmentId, null, "end_of_day", $deadline);
        } catch (Throwable $error) {
            error_log("End-of-day check-out failed for appointment {$appointmentId}: " . $error->getMessage());
        }
    }
}

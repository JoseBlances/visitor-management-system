<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";

auth_json_exception_guard();
auth_require_method("POST");
auth_require_csrf();
auth_require_schema($conn);

$pending = auth_require_pending();
if (empty($pending["need_two_factor"])) {
    auth_flow_next($conn);
}
$user = auth_pending_user($conn, $pending);
$userId = (int) $user["id"];
$context = auth_attempt_context($user, (string) $user["username"], "web", "two_factor");
auth_pending_lock_check($conn, $context);

$input = auth_json_body();
$useBackupCode = ($input["method"] ?? "") === "backup_code";
$code = trim((string) ($input["code"] ?? ""));

$verified = false;
if ($useBackupCode) {
    $verified = auth_consume_backup_code($conn, $userId, $code);
} else {
    $secret = auth_decrypt_secret($user["totp_secret"], $userId);
    $lastStep = $user["totp_last_used_step"] !== null ? (int) $user["totp_last_used_step"] : null;
    $step = $secret !== null ? auth_totp_verify($secret, $code, $lastStep) : null;
    if ($step !== null) {
        // Conditional update: two requests with the same code cannot both succeed.
        $stmt = $conn->prepare(
            "UPDATE app_users SET totp_last_used_step = ?
             WHERE id = ? AND (totp_last_used_step IS NULL OR totp_last_used_step < ?)"
        );
        $stmt->bind_param("iii", $step, $userId, $step);
        $stmt->execute();
        $verified = $stmt->affected_rows === 1;
        $stmt->close();
    }
}

if (!$verified) {
    $result = auth_register_failure($conn, $context, $useBackupCode ? "wrong_backup_code" : "wrong_code");
    if ($result["lock"]) {
        unset($_SESSION["auth_pending"]);
        auth_lock_response($result["lock"]);
    }
    auth_json_error(401, "invalid_code", $useBackupCode
        ? "That backup code didn't work or was already used."
        : "That code didn't work. Check the 6-digit code in your authenticator app and try again.", [
        "attempts_remaining" => $result["remaining"],
        "next_lock_minutes" => $result["next_lock_minutes"],
    ]);
}

$_SESSION["auth_pending"]["need_two_factor"] = false;
$_SESSION["auth_pending"]["method"] = $useBackupCode ? "password+backup_code" : "password+authenticator";
if ($useBackupCode) {
    auth_audit($conn, $userId, "auth.backup_code_used", "app_user", (string) $userId, [
        "remaining" => auth_backup_codes_remaining($conn, $userId),
    ]);
}
if (!empty($input["trust_device"])) {
    auth_trust_device($conn, $userId);
    auth_audit($conn, $userId, "auth.device_trusted", "app_user", (string) $userId, [
        "device" => auth_device_label(auth_user_agent()),
        "days" => AUTH_TRUSTED_DEVICE_DAYS,
    ]);
}

auth_flow_next($conn);

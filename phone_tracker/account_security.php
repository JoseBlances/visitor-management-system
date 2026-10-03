<?php
/*
 * Self-service account security for staff: password, two-step verification,
 * backup codes, trusted browsers, other sessions, and recent sign-in activity.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";

auth_json_exception_guard();
auth_require_method("GET", "POST");
require_permission_json("profile.self");
auth_require_schema($conn);

$user = auth_load_user($conn, (int) $_SESSION["user_id"]);
$userId = (int) $user["id"];
$role = (string) $user["role"];

function account_security_overview(mysqli $conn, array $user): array
{
    $userId = (int) $user["id"];
    $role = (string) $user["role"];

    $devices = [];
    $currentHash = auth_current_trusted_device_hash();
    $stmt = $conn->prepare(
        "SELECT id, token_hash, label, ip_address, created_at, last_used_at, expires_at
         FROM user_trusted_devices
         WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
         ORDER BY COALESCE(last_used_at, created_at) DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $devices[] = [
            "id" => (int) $row["id"],
            "label" => (string) $row["label"],
            "ip" => (string) $row["ip_address"],
            "created_at" => $row["created_at"],
            "last_used_at" => $row["last_used_at"],
            "expires_at" => $row["expires_at"],
            "current" => $currentHash !== "" && hash_equals((string) $row["token_hash"], $currentHash),
        ];
    }
    $stmt->close();

    $activity = [];
    $stmt = $conn->prepare(
        "SELECT created_at, outcome, reason, stage, channel, ip_address, user_agent
         FROM auth_login_attempts WHERE user_id = ? ORDER BY id DESC LIMIT 10"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $activity[] = [
            "created_at" => $row["created_at"],
            "outcome" => (string) $row["outcome"],
            "reason" => (string) $row["reason"],
            "stage" => (string) $row["stage"],
            "channel" => (string) $row["channel"],
            "ip" => (string) $row["ip_address"],
            "device" => auth_device_label((string) $row["user_agent"]),
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT password_changed_at, created_at FROM app_users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $dates = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        "success" => true,
        "account" => [
            "username" => (string) $user["username"],
            "display_name" => (string) $user["display_name"],
            "role" => $role,
            "password_changed_at" => $dates["password_changed_at"] ?? null,
            "created_at" => $dates["created_at"] ?? null,
        ],
        "two_factor" => [
            "enabled" => auth_user_has_two_factor($user),
            "required" => auth_two_factor_required_for($role),
            "allowed" => auth_two_factor_allowed_for($role),
            "enabled_at" => $user["totp_enabled_at"],
            "backup_codes_remaining" => auth_backup_codes_remaining($conn, $userId),
        ],
        "trusted_devices" => $devices,
        "recent_activity" => $activity,
        "password_policy" => auth_password_policy(),
        "identity" => [
            "username" => (string) $user["username"],
            "email" => (string) ($user["email"] ?? ""),
            "display_name" => (string) $user["display_name"],
        ],
        "session" => [
            "auth_method" => (string) ($_SESSION["auth_method"] ?? "password"),
            "login_at" => date("Y-m-d H:i:s", (int) $_SESSION["login_at"]),
            "expires_at" => date("Y-m-d H:i:s", (int) $_SESSION["login_at"] + AUTH_ABSOLUTE_TIMEOUT_SECONDS),
            "idle_timeout_seconds" => AUTH_IDLE_TIMEOUT_SECONDS,
            "step_up_valid" => time() - (int) ($_SESSION["step_up_at"] ?? 0) <= AUTH_STEP_UP_SECONDS,
        ],
    ];
}

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    auth_json(account_security_overview($conn, $user));
}

$input = auth_json_body();
$action = (string) ($input["action"] ?? "");

switch ($action) {
    case "change_password":
        $context = auth_attempt_context($user, (string) $user["username"], "web", "step_up");
        $lock = auth_active_lock($conn, $context["subject_key"], $context["ip"]);
        if ($lock) {
            auth_end_session("session_revoked");
            auth_lock_response($lock);
        }
        if (!password_verify((string) ($input["current_password"] ?? ""), (string) $user["password_hash"])) {
            $result = auth_register_failure($conn, $context, "wrong_password");
            if ($result["lock"]) {
                auth_end_session("session_revoked");
                auth_lock_response($result["lock"]);
            }
            auth_json_error(422, "invalid_current_password", "Your current password isn't correct.", [
                "attempts_remaining" => $result["remaining"],
            ]);
        }
        auth_register_success($conn, $context, "confirmed_password");
        $newPassword = (string) ($input["new_password"] ?? "");
        if ($newPassword !== (string) ($input["confirm_password"] ?? "")) {
            auth_json_error(422, "password_mismatch", "The two new passwords don't match.");
        }
        $problems = auth_password_problems($newPassword, $user);
        if (!$problems && password_verify($newPassword, (string) $user["password_hash"])) {
            $problems[] = "Choose a password different from your current one.";
        }
        if ($problems) {
            auth_json_error(422, "weak_password", $problems[0], ["problems" => $problems]);
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "UPDATE app_users
             SET password_hash = ?, must_change_password = 0, temp_password_expires_at = NULL,
                 password_changed_at = NOW(), session_version = session_version + 1
             WHERE id = ?"
        );
        $stmt->bind_param("si", $hash, $userId);
        $stmt->execute();
        $stmt->close();
        auth_revoke_trusted_devices($conn, $userId);
        // Other sessions end; this one continues.
        $_SESSION["auth_version"] = (int) $user["session_version"] + 1;
        $_SESSION["step_up_at"] = time();
        auth_audit($conn, $userId, "auth.password_changed", "app_user", (string) $userId, ["during" => "account_settings"]);
        auth_json(["success" => true, "message" => "Password changed. You were signed out on your other devices."]);

    case "two_factor_begin":
        if (!auth_two_factor_allowed_for($role)) {
            auth_json_error(403, "forbidden", "Two-step verification is available for staff accounts.");
        }
        if (auth_user_has_two_factor($user)) {
            auth_json_error(409, "already_enabled", "Two-step verification is already on.");
        }
        require_step_up_json();
        $secret = auth_totp_generate_secret();
        $_SESSION["totp_setup"] = ["secret" => $secret, "expires_at" => time() + 900];
        auth_json([
            "success" => true,
            "secret" => auth_totp_format_secret($secret),
            "otpauth_uri" => auth_totp_uri($secret, (string) $user["username"]),
            "issuer" => AUTH_TOTP_ISSUER,
            "account" => (string) $user["username"],
        ]);

    case "two_factor_confirm":
        require_step_up_json();
        $setup = $_SESSION["totp_setup"] ?? null;
        if (!is_array($setup) || time() > (int) $setup["expires_at"]) {
            unset($_SESSION["totp_setup"]);
            auth_json_error(409, "setup_not_started", "The setup expired. Start again to get a new QR code.");
        }
        $step = auth_totp_verify((string) $setup["secret"], (string) ($input["code"] ?? ""), null);
        if ($step === null) {
            auth_json_error(422, "invalid_code", "That code didn't match. Enter the newest 6-digit code from the app.");
        }
        $encrypted = auth_encrypt_secret((string) $setup["secret"], $userId);
        $codes = auth_generate_backup_codes();
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE app_users SET totp_secret = ?, totp_enabled_at = NOW(), totp_last_used_step = ? WHERE id = ?");
            $stmt->bind_param("sii", $encrypted, $step, $userId);
            $stmt->execute();
            $stmt->close();
            auth_store_backup_codes($conn, $userId, $codes);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        unset($_SESSION["totp_setup"]);
        auth_audit($conn, $userId, "auth.two_factor_enabled", "app_user", (string) $userId, ["during" => "account_settings"]);
        auth_json(["success" => true, "backup_codes" => $codes]);

    case "two_factor_disable":
        if (auth_two_factor_required_for($role)) {
            auth_json_error(403, "two_factor_required", "Two-step verification is required for your role and can't be turned off.");
        }
        require_step_up_json();
        $stmt = $conn->prepare("UPDATE app_users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_used_step = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        auth_store_backup_codes($conn, $userId, []);
        auth_revoke_trusted_devices($conn, $userId);
        auth_audit($conn, $userId, "auth.two_factor_disabled", "app_user", (string) $userId, ["by" => "self"]);
        auth_json(["success" => true, "message" => "Two-step verification is off."]);

    case "backup_codes_regenerate":
        if (!auth_user_has_two_factor($user)) {
            auth_json_error(409, "not_enabled", "Turn on two-step verification first.");
        }
        require_step_up_json();
        $codes = auth_generate_backup_codes();
        auth_store_backup_codes($conn, $userId, $codes);
        auth_audit($conn, $userId, "auth.backup_codes_regenerated", "app_user", (string) $userId);
        auth_json(["success" => true, "backup_codes" => $codes]);

    case "trusted_devices_forget":
        $deviceId = isset($input["device_id"]) ? (int) $input["device_id"] : null;
        $count = auth_revoke_trusted_devices($conn, $userId, $deviceId);
        auth_audit($conn, $userId, "auth.trusted_devices_forgotten", "app_user", (string) $userId, ["count" => $count]);
        auth_json(["success" => true, "forgotten" => $count]);

    case "sign_out_others":
        auth_bump_session_version($conn, $userId);
        $_SESSION["auth_version"] = (int) $user["session_version"] + 1;
        auth_audit($conn, $userId, "auth.signed_out_other_sessions", "app_user", (string) $userId);
        auth_json(["success" => true, "message" => "You were signed out everywhere else."]);

    default:
        auth_json_error(400, "invalid_action", "Unknown action.");
}

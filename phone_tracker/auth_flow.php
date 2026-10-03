<?php

/*
 * The website sign-in flow. After the password is accepted the browser holds a
 * short-lived "pending" sign-in (not a signed-in session) until the remaining
 * steps are done, in this order:
 *   1. two-step verification, when the account has it (unless the browser is trusted)
 *   2. a new password, when the current one is temporary or the published default
 *   3. two-step verification setup, when the role requires it and it is not set up
 * Each step endpoint answers with the next step, or signs the user in.
 */

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/auth_totp.php";

function auth_load_user(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, username, email, password_hash, display_name, role, office_code, is_active,
                must_change_password, temp_password_expires_at, session_version, totp_secret,
                totp_enabled_at, totp_last_used_step, last_login_at, last_login_ip,
                (temp_password_expires_at IS NOT NULL AND temp_password_expires_at < NOW()) AS temp_password_expired
         FROM app_users WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

/** Finds the account for a username or email; usernames win if both could match. */
function auth_find_login_user(mysqli $conn, string $identifier): ?array
{
    $normalized = auth_normalize_identifier($identifier);
    $stmt = $conn->prepare(
        "SELECT id FROM app_users
         WHERE LOWER(username) = ? OR LOWER(email) = ?
         ORDER BY (LOWER(username) = ?) DESC, id ASC
         LIMIT 1"
    );
    $stmt->bind_param("sss", $normalized, $normalized, $normalized);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? auth_load_user($conn, (int) $row["id"]) : null;
}

function auth_user_has_two_factor(array $user): bool
{
    return !empty($user["totp_enabled_at"]) && !empty($user["totp_secret"]);
}

function auth_lock_response(array $lock): never
{
    $retryAfter = max(1, (int) $lock["retry_after"]);
    header("Retry-After: " . $retryAfter);
    auth_json_error(429, "locked", auth_lock_message((string) $lock["scope"]), [
        "retry_after" => $retryAfter,
        "lock_scope" => (string) $lock["scope"],
    ]);
}

function auth_pending(): ?array
{
    $pending = $_SESSION["auth_pending"] ?? null;
    if (!is_array($pending)) {
        return null;
    }
    if (time() > (int) ($pending["expires_at"] ?? 0)) {
        unset($_SESSION["auth_pending"]);
        return null;
    }
    return $pending;
}

function auth_require_pending(): array
{
    $pending = auth_pending();
    if (!$pending) {
        auth_json_error(401, "pending_expired", "Your sign-in timed out. Please start again.");
    }
    return $pending;
}

/** Loads the pending user and checks the account is still usable. */
function auth_pending_user(mysqli $conn, array $pending): array
{
    $user = auth_load_user($conn, (int) $pending["user_id"]);
    if (!$user || (int) $user["is_active"] !== 1 || (int) $user["session_version"] !== (int) $pending["session_version"]) {
        unset($_SESSION["auth_pending"]);
        auth_json_error(401, "pending_expired", "Your sign-in could not be completed. Please start again.");
    }
    return $user;
}

/** Stops the pending sign-in if the account or this IP was locked in the meantime. */
function auth_pending_lock_check(mysqli $conn, array $context): void
{
    $lock = auth_active_lock($conn, $context["subject_key"], $context["ip"]);
    if ($lock) {
        unset($_SESSION["auth_pending"]);
        auth_note_blocked_attempt($conn, (int) $lock["id"]);
        auth_lock_response($lock);
    }
}

/** Answers with the next sign-in step, or completes the sign-in. */
function auth_flow_next(mysqli $conn): never
{
    $pending = auth_require_pending();
    $user = auth_pending_user($conn, $pending);
    $name = (string) ($user["display_name"] ?: $user["username"]);

    if (!empty($pending["need_two_factor"])) {
        auth_json([
            "success" => true,
            "status" => "two_factor_required",
            "display_name" => $name,
            "backup_codes_remaining" => auth_backup_codes_remaining($conn, (int) $user["id"]),
            "trusted_device_days" => AUTH_TRUSTED_DEVICE_DAYS,
        ]);
    }
    if (!empty($pending["need_password_change"])) {
        auth_json([
            "success" => true,
            "status" => "password_change_required",
            "reason" => (string) $pending["password_change_reason"],
            "display_name" => $name,
            "username" => (string) $user["username"],
            "email" => (string) ($user["email"] ?? ""),
            "policy" => auth_password_policy(),
        ]);
    }
    if (!empty($pending["need_two_factor_setup"])) {
        auth_json([
            "success" => true,
            "status" => "two_factor_setup_required",
            "display_name" => $name,
            "role" => (string) $user["role"],
        ]);
    }
    auth_json(auth_complete_login($conn, $user, $pending));
}

function auth_complete_login(mysqli $conn, array $user, array $pending): array
{
    $method = (string) ($pending["method"] ?? "password");
    $now = time();

    session_regenerate_id(true);
    unset($_SESSION["auth_pending"]);
    $_SESSION["user_id"] = (int) $user["id"];
    $_SESSION["role"] = (string) $user["role"];
    $_SESSION["username"] = (string) $user["username"];
    $_SESSION["display_name"] = (string) $user["display_name"];
    $_SESSION["office_code"] = (string) $user["office_code"];
    $_SESSION["auth_version"] = (int) $user["session_version"];
    $_SESSION["auth_method"] = $method;
    $_SESSION["login_at"] = $now;
    $_SESSION["last_activity"] = $now;
    $_SESSION["step_up_at"] = $now;
    auth_rotate_csrf_token();

    $ip = auth_client_ip();
    $userId = (int) $user["id"];
    $update = $conn->prepare("UPDATE app_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
    $update->bind_param("si", $ip, $userId);
    $update->execute();
    $update->close();

    auth_register_success($conn, auth_attempt_context($user, (string) $user["username"], "web", "password"), $method);
    auth_audit($conn, $userId, "auth.signed_in", "app_user", (string) $userId, [
        "method" => $method,
        "device" => auth_device_label(auth_user_agent()),
    ]);

    $role = (string) $user["role"];
    $payload = [
        "success" => true,
        "status" => "authenticated",
        "user_id" => $userId,
        "role" => $role,
        "username" => (string) $user["username"],
        "display_name" => (string) $user["display_name"],
        "office_code" => (string) $user["office_code"],
        "redirect" => AUTH_ROLE_HOME[$role] ?? "index.html",
        "permissions" => auth_role_permissions($role),
        "auth_method" => $method,
        "previous_login" => !empty($pending["previous_login_at"])
            ? ["at" => (string) $pending["previous_login_at"], "ip" => (string) ($pending["previous_login_ip"] ?? "")]
            : null,
    ];
    if (!empty($pending["new_backup_codes"]) && is_array($pending["new_backup_codes"])) {
        $payload["backup_codes"] = array_values($pending["new_backup_codes"]);
    }
    if ($method === "password+backup_code") {
        $payload["backup_codes_remaining"] = auth_backup_codes_remaining($conn, $userId);
    }
    return $payload;
}

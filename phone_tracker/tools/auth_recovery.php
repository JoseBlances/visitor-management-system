<?php
/*
 * Emergency account recovery from the server's command line, for when no
 * administrator can sign in (for example the only admin lost their phone).
 *
 *   C:\xampp\php\php.exe phone_tracker\tools\auth_recovery.php unlock <username>
 *   C:\xampp\php\php.exe phone_tracker\tools\auth_recovery.php reset-password <username>
 *   C:\xampp\php\php.exe phone_tracker\tools\auth_recovery.php reset-2fa <username>
 *
 * Every action is written to audit_logs.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/auth_security.php";

$command = $argv[1] ?? "";
$username = $argv[2] ?? "";
$commands = ["unlock", "reset-password", "reset-2fa"];
if (!in_array($command, $commands, true) || $username === "") {
    fwrite(STDERR, "Usage: php auth_recovery.php <" . implode("|", $commands) . "> <username>\n");
    exit(1);
}
if (!auth_schema_ready($conn)) {
    fwrite(STDERR, AUTH_MIGRATION_MESSAGE . "\n");
    exit(1);
}

$stmt = $conn->prepare("SELECT id, username, display_name, role FROM app_users WHERE LOWER(username) = LOWER(?) LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    fwrite(STDERR, "No account with username \"" . $username . "\".\n");
    exit(1);
}
$userId = (int) $user["id"];
$label = $user["display_name"] . " (" . $user["username"] . ", " . $user["role"] . ")";

if ($command === "unlock") {
    $released = auth_release_user_locks($conn, $userId, null);
    auth_audit($conn, null, "user.unlocked", "app_user", (string) $userId, ["by" => "command_line", "locks" => $released]);
    echo "Released " . $released . " active lock(s) for " . $label . ".\n";
    exit(0);
}

if ($command === "reset-password") {
    $temporaryPassword = auth_generate_temporary_password();
    $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $hours = AUTH_TEMP_PASSWORD_HOURS;
    $stmt = $conn->prepare(
        "UPDATE app_users
         SET password_hash = ?, must_change_password = 1, temp_password_expires_at = NOW() + INTERVAL {$hours} HOUR,
             password_changed_at = NOW(), session_version = session_version + 1, is_active = 1
         WHERE id = ?"
    );
    $stmt->bind_param("si", $hash, $userId);
    $stmt->execute();
    $stmt->close();
    auth_revoke_trusted_devices($conn, $userId);
    auth_release_user_locks($conn, $userId, null);
    auth_audit($conn, null, "user.password_reset", "app_user", (string) $userId, ["by" => "command_line"]);
    echo "Temporary password for " . $label . ": " . $temporaryPassword . "\n";
    echo "It expires in " . $hours . " hours and must be changed at the next sign-in.\n";
    exit(0);
}

$stmt = $conn->prepare(
    "UPDATE app_users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_used_step = NULL,
         session_version = session_version + 1
     WHERE id = ?"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->close();
auth_store_backup_codes($conn, $userId, []);
auth_revoke_trusted_devices($conn, $userId);
auth_audit($conn, null, "auth.two_factor_disabled", "app_user", (string) $userId, ["by" => "command_line"]);
echo "Two-step verification was reset for " . $label . ".";
echo auth_two_factor_required_for((string) $user["role"]) ? " They will set it up again at the next sign-in.\n" : "\n";

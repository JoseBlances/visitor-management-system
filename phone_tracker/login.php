<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";

auth_json_exception_guard();
auth_require_method("POST");
auth_require_csrf();
auth_require_schema($conn);

$input = auth_json_body();
$identifier = trim((string) ($input["username"] ?? ""));
$password = (string) ($input["password"] ?? "");
if ($identifier === "" || $password === "") {
    auth_json_error(422, "missing_fields", "Enter your username or email and your password.");
}
if (mb_strlen($identifier, "UTF-8") > 190 || strlen($password) > 1024) {
    auth_json_error(422, "invalid_credentials", "Incorrect username or password.");
}

// A new sign-in replaces whoever was signed in (or half signed in) in this browser.
$_SESSION = ["csrf_token" => auth_csrf_token()];

$user = auth_find_login_user($conn, $identifier);
$context = auth_attempt_context($user, $identifier, "web", "password");

$lock = auth_active_lock($conn, $context["subject_key"], $context["ip"]);
if ($lock) {
    auth_note_blocked_attempt($conn, (int) $lock["id"]);
    auth_lock_response($lock);
}

// Always run one bcrypt check so unknown usernames take as long as wrong passwords.
$passwordMatches = password_verify($password, $user ? (string) $user["password_hash"] : AUTH_DUMMY_PASSWORD_HASH);
$failure = "";
if (!$user) {
    $failure = "unknown_account";
} elseif (!$passwordMatches) {
    $failure = "wrong_password";
} elseif ((int) $user["is_active"] !== 1) {
    $failure = "suspended";
} elseif ((int) $user["temp_password_expired"] === 1 && (int) $user["must_change_password"] === 1) {
    $failure = "temporary_password_expired";
}

if ($failure !== "") {
    $result = auth_register_failure($conn, $context, $failure);
    if ($result["lock"]) {
        auth_lock_response($result["lock"]);
    }
    if ($failure === "temporary_password_expired") {
        auth_json_error(403, "temporary_password_expired", "This temporary password has expired. Ask an administrator to reset your password.");
    }
    auth_json_error(401, "invalid_credentials", "Incorrect username or password.", [
        "attempts_remaining" => $result["remaining"],
        "lock_threshold" => AUTH_LOCK_THRESHOLD,
        "next_lock_minutes" => $result["next_lock_minutes"],
    ]);
}

$userId = (int) $user["id"];
if (password_needs_rehash((string) $user["password_hash"], PASSWORD_DEFAULT)) {
    $rehash = password_hash($password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE app_users SET password_hash = ? WHERE id = ?");
    $update->bind_param("si", $rehash, $userId);
    $update->execute();
    $update->close();
}

$hasTwoFactor = auth_user_has_two_factor($user);
$trustedDevice = $hasTwoFactor && auth_trusted_device_valid($conn, $userId);

session_regenerate_id(true);
$_SESSION["auth_pending"] = [
    "user_id" => $userId,
    "session_version" => (int) $user["session_version"],
    "expires_at" => time() + AUTH_PENDING_TIMEOUT_SECONDS,
    "need_two_factor" => $hasTwoFactor && !$trustedDevice,
    "need_password_change" => (int) $user["must_change_password"] === 1,
    "password_change_reason" => $user["temp_password_expires_at"] !== null ? "temporary" : "default",
    "need_two_factor_setup" => !$hasTwoFactor && auth_two_factor_required_for((string) $user["role"]),
    "method" => $trustedDevice ? "password+trusted_device" : "password",
    "previous_login_at" => $user["last_login_at"],
    "previous_login_ip" => $user["last_login_ip"],
];

auth_flow_next($conn);

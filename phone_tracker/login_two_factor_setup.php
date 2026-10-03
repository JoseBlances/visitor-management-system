<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";

auth_json_exception_guard();
auth_require_method("GET", "POST");
auth_require_csrf();
auth_require_schema($conn);

$pending = auth_require_pending();
if (!empty($pending["need_two_factor"]) || !empty($pending["need_password_change"])) {
    auth_json_error(409, "step_out_of_order", "Finish the previous sign-in step first.");
}
if (empty($pending["need_two_factor_setup"])) {
    auth_flow_next($conn);
}
$user = auth_pending_user($conn, $pending);
$userId = (int) $user["id"];

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    if (empty($_SESSION["auth_pending"]["totp_setup_secret"])) {
        $_SESSION["auth_pending"]["totp_setup_secret"] = auth_totp_generate_secret();
    }
    $secret = (string) $_SESSION["auth_pending"]["totp_setup_secret"];
    auth_json([
        "success" => true,
        "secret" => auth_totp_format_secret($secret),
        "otpauth_uri" => auth_totp_uri($secret, (string) $user["username"]),
        "issuer" => AUTH_TOTP_ISSUER,
        "account" => (string) $user["username"],
    ]);
}

$secret = (string) ($pending["totp_setup_secret"] ?? "");
if ($secret === "") {
    auth_json_error(409, "setup_not_started", "Start the setup again to get a new QR code.");
}
$context = auth_attempt_context($user, (string) $user["username"], "web", "two_factor");
auth_pending_lock_check($conn, $context);

$input = auth_json_body();
$step = auth_totp_verify($secret, (string) ($input["code"] ?? ""), null);
if ($step === null) {
    $result = auth_register_failure($conn, $context, "setup_wrong_code");
    if ($result["lock"]) {
        unset($_SESSION["auth_pending"]);
        auth_lock_response($result["lock"]);
    }
    auth_json_error(401, "invalid_code", "That code didn't match. Make sure you scanned the code shown here, then enter the newest 6-digit code.", [
        "attempts_remaining" => $result["remaining"],
        "next_lock_minutes" => $result["next_lock_minutes"],
    ]);
}

$encrypted = auth_encrypt_secret($secret, $userId);
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
auth_audit($conn, $userId, "auth.two_factor_enabled", "app_user", (string) $userId, ["during" => "sign_in"]);

unset($_SESSION["auth_pending"]["totp_setup_secret"]);
$_SESSION["auth_pending"]["need_two_factor_setup"] = false;
$_SESSION["auth_pending"]["method"] = "password+authenticator";
$_SESSION["auth_pending"]["new_backup_codes"] = $codes;

auth_flow_next($conn);

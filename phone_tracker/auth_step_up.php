<?php
/*
 * "Confirm it's you": re-checks the signed-in user's password or authenticator code
 * before sensitive actions. Valid for AUTH_STEP_UP_SECONDS. Wrong answers count
 * toward the lockout, and a lockout here signs the user out.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";

auth_json_exception_guard();
auth_require_method("POST");
require_login_json();

$user = auth_load_user($conn, (int) $_SESSION["user_id"]);
$userId = (int) $user["id"];
$context = auth_attempt_context($user, (string) $user["username"], "web", "step_up");

$lock = auth_active_lock($conn, $context["subject_key"], $context["ip"]);
if ($lock) {
    auth_end_session("session_revoked");
    auth_lock_response($lock);
}

$input = auth_json_body();
$method = ($input["method"] ?? "password") === "authenticator" ? "authenticator" : "password";
$verified = false;
if ($method === "password") {
    $verified = password_verify((string) ($input["password"] ?? ""), (string) $user["password_hash"]);
} elseif (auth_user_has_two_factor($user)) {
    $secret = auth_decrypt_secret($user["totp_secret"], $userId);
    $lastStep = $user["totp_last_used_step"] !== null ? (int) $user["totp_last_used_step"] : null;
    $step = $secret !== null ? auth_totp_verify($secret, (string) ($input["code"] ?? ""), $lastStep) : null;
    if ($step !== null) {
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
    $result = auth_register_failure($conn, $context, $method === "password" ? "wrong_password" : "wrong_code");
    if ($result["lock"]) {
        auth_end_session("session_revoked");
        auth_lock_response($result["lock"]);
    }
    auth_json_error(422, "invalid_step_up", $method === "password"
        ? "That password isn't correct."
        : "That code didn't work. Enter the newest code from your authenticator app.", [
        "attempts_remaining" => $result["remaining"],
    ]);
}

// A correct answer proves the account, so it restarts the failed-attempt count like a sign-in.
auth_register_success($conn, $context, "confirmed_" . $method);
$_SESSION["step_up_at"] = time();
auth_json(["success" => true, "valid_for_seconds" => AUTH_STEP_UP_SECONDS]);

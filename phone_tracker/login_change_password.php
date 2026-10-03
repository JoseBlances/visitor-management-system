<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";

auth_json_exception_guard();
auth_require_method("POST");
auth_require_csrf();
auth_require_schema($conn);

$pending = auth_require_pending();
if (!empty($pending["need_two_factor"])) {
    auth_json_error(409, "step_out_of_order", "Finish two-step verification first.");
}
if (empty($pending["need_password_change"])) {
    auth_flow_next($conn);
}
$user = auth_pending_user($conn, $pending);
$userId = (int) $user["id"];
auth_pending_lock_check($conn, auth_attempt_context($user, (string) $user["username"], "web", "password"));

$input = auth_json_body();
$newPassword = (string) ($input["new_password"] ?? "");
$confirmPassword = (string) ($input["confirm_password"] ?? "");
if ($newPassword !== $confirmPassword) {
    auth_json_error(422, "password_mismatch", "The two passwords don't match.");
}
$problems = auth_password_problems($newPassword, $user);
if (!$problems && password_verify($newPassword, (string) $user["password_hash"])) {
    $problems[] = "Choose a different password from the one you signed in with.";
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
auth_audit($conn, $userId, "auth.password_changed", "app_user", (string) $userId, [
    "reason" => (string) $pending["password_change_reason"],
    "during" => "sign_in",
]);

// The password change ended every other session; this sign-in continues with the new version.
$_SESSION["auth_pending"]["need_password_change"] = false;
$_SESSION["auth_pending"]["session_version"] = (int) $pending["session_version"] + 1;

auth_flow_next($conn);

<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$user = auth_current_user();
if (!$user) {
    echo json_encode([
        "authenticated" => false,
        "reason" => $GLOBALS["auth_session_end_reason"] ?: "not_authenticated",
    ]);
    exit;
}

echo json_encode([
    "authenticated" => true,
    "user_id" => $user["id"],
    "role" => $user["role"],
    "username" => $user["username"],
    "display_name" => $user["display_name"],
    "office_code" => $user["office_code"],
    "permissions" => auth_role_permissions($user["role"]),
    "two_factor_enabled" => $user["two_factor_enabled"],
    "two_factor_required" => auth_two_factor_required_for($user["role"]),
    "auth_method" => (string) ($_SESSION["auth_method"] ?? "password"),
    "idle_timeout_seconds" => auth_idle_timeout_seconds(),
    "session_expires_at" => auth_session_max_seconds() > 0
        ? date("c", (int) $_SESSION["login_at"] + auth_session_max_seconds())
        : null,
]);

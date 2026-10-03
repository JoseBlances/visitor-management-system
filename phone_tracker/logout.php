<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";

auth_require_method("POST");

$userId = (int) ($_SESSION["user_id"] ?? 0);
if ($userId > 0 && auth_schema_ready($conn)) {
    auth_audit($conn, $userId, "auth.signed_out", "app_user", (string) $userId);
}

$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), "", [
    "expires" => time() - 42000,
    "path" => $params["path"],
    "domain" => $params["domain"],
    "secure" => $params["secure"],
    "httponly" => $params["httponly"],
    "samesite" => $params["samesite"] ?: "Strict",
]);
session_destroy();
// The next request starts a new session and issues a new CSRF token.
setcookie(AUTH_CSRF_COOKIE, "", [
    "expires" => time() - 42000,
    "path" => "/",
    "secure" => auth_request_is_https(),
    "httponly" => false,
    "samesite" => "Strict",
]);

auth_json(["success" => true]);

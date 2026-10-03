<?php
/*
 * Session handling for the staff and visitor web pages. Every protected endpoint
 * calls one of the require_*_json() guards below, which:
 *   - use a hardened session cookie (HttpOnly, SameSite=Strict, Secure on HTTPS),
 *   - sign the user out after 30 minutes idle or 12 hours in total,
 *   - re-check the account on every request, so suspending a user, deleting them,
 *     resetting their password, or "sign out everywhere" takes effect immediately,
 *   - require the CSRF token (X-CSRF-Token header) on every POST/PUT/PATCH/DELETE.
 * auth.js sends the CSRF token automatically.
 */

require_once __DIR__ . "/auth_config.php";
require_once __DIR__ . "/auth_security.php";
require_once __DIR__ . "/permissions.php";

$GLOBALS["auth_session_end_reason"] = "";

auth_start_session();

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");
    ini_set("session.cookie_httponly", "1");
    // Expiry is enforced below; keep PHP's garbage collector from removing sessions earlier.
    ini_set("session.gc_maxlifetime", "7200");
    session_name(AUTH_SESSION_NAME);
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "domain" => "",
        "secure" => auth_request_is_https(),
        "httponly" => true,
        "samesite" => "Strict",
    ]);
    session_start();
    auth_ensure_csrf_cookie();
}

function auth_db(): mysqli
{
    if (isset($GLOBALS["conn"]) && $GLOBALS["conn"] instanceof mysqli) {
        return $GLOBALS["conn"];
    }
    require __DIR__ . "/db.php";
    $GLOBALS["conn"] = $conn;
    return $conn;
}

/* ---------- JSON responses ---------- */

function auth_json(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-store");
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_json_error(int $status, string $code, string $message, array $extra = []): never
{
    if (!headers_sent()) {
        header("X-Auth-Error: " . $code);
    }
    auth_json(array_merge(["success" => false, "code" => $code, "message" => $message], $extra), $status);
}

/** Turns unexpected exceptions in the sign-in endpoints into a JSON error instead of an HTML page. */
function auth_json_exception_guard(): void
{
    set_exception_handler(function (Throwable $error): void {
        error_log("Auth endpoint error: " . $error->getMessage() . " in " . $error->getFile() . ":" . $error->getLine());
        if ($error instanceof mysqli_sql_exception && !auth_schema_ready(auth_db())) {
            auth_json_error(503, "migration_required", AUTH_MIGRATION_MESSAGE);
        }
        auth_json_error(500, "server_error", "Something went wrong on the server. Please try again.");
    });
}

function auth_require_method(string ...$methods): void
{
    $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if (!in_array($method, $methods, true)) {
        header("Allow: " . implode(", ", $methods));
        auth_json_error(405, "method_not_allowed", "Method not allowed");
    }
}

function auth_json_body(): array
{
    $raw = file_get_contents("php://input");
    if ($raw === false || strlen($raw) > 65536) {
        auth_json_error(413, "invalid_request", "The request is too large.");
    }
    $data = trim($raw) === "" ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        auth_json_error(400, "invalid_request", "Invalid request body");
    }
    return $data;
}

function auth_require_schema(mysqli $conn): void
{
    if (!auth_schema_ready($conn)) {
        auth_json_error(503, "migration_required", AUTH_MIGRATION_MESSAGE);
    }
}

/* ---------- CSRF ---------- */

function auth_csrf_token(): string
{
    if (empty($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

function auth_set_csrf_cookie(string $token): void
{
    if (headers_sent()) {
        return;
    }
    // Readable by auth.js on purpose; it is useless without the HttpOnly session cookie.
    setcookie(AUTH_CSRF_COOKIE, $token, [
        "expires" => 0,
        "path" => "/",
        "secure" => auth_request_is_https(),
        "httponly" => false,
        "samesite" => "Strict",
    ]);
    $_COOKIE[AUTH_CSRF_COOKIE] = $token;
}

function auth_ensure_csrf_cookie(): void
{
    $token = auth_csrf_token();
    if ((string) ($_COOKIE[AUTH_CSRF_COOKIE] ?? "") !== $token) {
        auth_set_csrf_cookie($token);
    }
}

function auth_rotate_csrf_token(): void
{
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    auth_set_csrf_cookie($_SESSION["csrf_token"]);
}

function auth_require_csrf(): void
{
    $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if (in_array($method, ["GET", "HEAD", "OPTIONS"], true)) {
        return;
    }
    $sent = (string) ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    if ($sent === "" || !hash_equals(auth_csrf_token(), $sent)) {
        auth_json_error(403, "csrf_failed", "Your security token expired. Refresh the page and try again.");
    }
}

/* ---------- Signed-in user ---------- */

/** Clears the signed-in user (and any half-finished sign-in) but keeps the session itself. */
function auth_end_session(string $reason): void
{
    $GLOBALS["auth_session_end_reason"] = $reason;
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    auth_ensure_csrf_cookie();
}

/**
 * The signed-in user, re-validated against the database once per request.
 *
 * @return array{id:int, username:string, display_name:string, role:string, office_code:string, two_factor_enabled:bool}|null
 */
function auth_current_user(): ?array
{
    static $resolved = false;
    static $user = null;
    if ($resolved) {
        return $user;
    }
    $resolved = true;

    $userId = (int) ($_SESSION["user_id"] ?? 0);
    if ($userId <= 0) {
        $GLOBALS["auth_session_end_reason"] = "not_authenticated";
        return null;
    }
    $now = time();
    $loginAt = (int) ($_SESSION["login_at"] ?? 0);
    $lastActivity = (int) ($_SESSION["last_activity"] ?? 0);
    if ($loginAt <= 0 || $lastActivity <= 0) {
        auth_end_session("session_expired");
        return null;
    }
    if ($now - $lastActivity > AUTH_IDLE_TIMEOUT_SECONDS) {
        auth_end_session("idle_timeout");
        return null;
    }
    if ($now - $loginAt > AUTH_ABSOLUTE_TIMEOUT_SECONDS) {
        auth_end_session("session_expired");
        return null;
    }

    $conn = auth_db();
    try {
        $stmt = $conn->prepare(
            "SELECT id, username, display_name, role, office_code, is_active, session_version, totp_enabled_at
             FROM app_users WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $error) {
        auth_json_error(503, "migration_required", AUTH_MIGRATION_MESSAGE);
    }
    if (!$row || (int) $row["is_active"] !== 1 || (int) $row["session_version"] !== (int) ($_SESSION["auth_version"] ?? -1)) {
        auth_end_session("session_revoked");
        return null;
    }

    $_SESSION["role"] = (string) $row["role"];
    $_SESSION["username"] = (string) $row["username"];
    $_SESSION["display_name"] = (string) $row["display_name"];
    $_SESSION["office_code"] = (string) $row["office_code"];
    $_SESSION["last_activity"] = $now;

    // "Last seen" for the admin's personnel view, written at most once a minute.
    if ($now - (int) ($_SESSION["last_seen_written"] ?? 0) >= 60) {
        $_SESSION["last_seen_written"] = $now;
        try {
            $seen = $conn->prepare("UPDATE app_users SET last_seen_at = NOW() WHERE id = ?");
            $seen->bind_param("i", $userId);
            $seen->execute();
            $seen->close();
        } catch (mysqli_sql_exception $error) {
            // personnel_directory_migration.sql has not been imported yet.
        }
    }

    $user = [
        "id" => (int) $row["id"],
        "username" => (string) $row["username"],
        "display_name" => (string) $row["display_name"],
        "role" => (string) $row["role"],
        "office_code" => (string) $row["office_code"],
        "two_factor_enabled" => !empty($row["totp_enabled_at"]),
    ];
    return $user;
}

function auth_session_end_message(string $reason): string
{
    $messages = [
        "idle_timeout" => "You were signed out after 30 minutes of inactivity.",
        "session_expired" => "Your session expired. Please sign in again.",
        "session_revoked" => "Your session was ended. Please sign in again.",
    ];
    return $messages[$reason] ?? "Not authenticated";
}

/* ---------- Guards ---------- */

function require_login_json(): void
{
    header("Content-Type: application/json; charset=utf-8");
    if (!auth_current_user()) {
        $reason = $GLOBALS["auth_session_end_reason"] ?: "not_authenticated";
        auth_json_error(401, $reason, auth_session_end_message($reason));
    }
    auth_require_csrf();
}

function require_permission_json(string ...$permissions): void
{
    require_login_json();
    foreach ($permissions as $permission) {
        if (!auth_role_can((string) $_SESSION["role"], $permission)) {
            auth_json_error(403, "forbidden", "Forbidden");
        }
    }
}

function require_admin_json(): void
{
    require_login_json();
    if ($_SESSION["role"] !== "admin") {
        auth_json_error(403, "forbidden", "Forbidden");
    }
}

/**
 * @param string[] $roles
 */
function require_roles_json(array $roles): void
{
    require_login_json();
    if (!in_array($_SESSION["role"], $roles, true)) {
        auth_json_error(403, "forbidden", "Forbidden");
    }
}

/** For sensitive actions: the user must have entered their password or code in the last 15 minutes. */
function require_step_up_json(): void
{
    $confirmedAt = (int) ($_SESSION["step_up_at"] ?? 0);
    if (time() - $confirmedAt > AUTH_STEP_UP_SECONDS) {
        $user = auth_current_user();
        auth_json_error(403, "step_up_required", "Confirm it's you to continue.", [
            "methods" => !empty($user["two_factor_enabled"]) ? ["password", "authenticator"] : ["password"],
        ]);
    }
}

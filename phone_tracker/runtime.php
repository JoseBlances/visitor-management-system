<?php
/**
 * Settings shared by every page and worker, read from environment variables so the same
 * code runs on a laptop (XAMPP, nothing to set) and on a hosted server (DEPLOYMENT.md).
 * db.php and session_bootstrap.php load this first.
 */

date_default_timezone_set("Asia/Manila");

/** An environment variable, or $default when it is missing or empty. */
function isatu_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === "") {
        // Apache SetEnv values reach PHP through $_SERVER.
        $value = $_SERVER[$name] ?? ($_ENV[$name] ?? null);
    }
    return $value === null || $value === "" ? $default : (string) $value;
}

/** True for "1", "true", "yes", or "on". */
function isatu_env_flag(string $name): bool
{
    return in_array(strtolower(trim((string) isatu_env($name, ""))), ["1", "true", "yes", "on"], true);
}

/**
 * Where the database is. A hosted server sets ISATU_DATABASE_URL
 * (mysql://user:password@host:port/database, for example a Railway variable reference) or
 * the separate ISATU_DB_HOST, ISATU_DB_PORT, ISATU_DB_NAME, ISATU_DB_USER, and
 * ISATU_DB_PASSWORD, which win over the URL. Without them it is local XAMPP.
 *
 * @return array{host: string, port: int, name: string, user: string, password: string}
 */
function isatu_db_settings(): array
{
    $settings = [
        "host" => (string) isatu_env("MYSQLHOST", "localhost"),
        "port" => (int) isatu_env("MYSQLPORT", "3306"),
        "name" => (string) isatu_env("MYSQLDATABASE", "phone_tracker"),
        "user" => (string) isatu_env("MYSQLUSER", "root"),
        "password" => (string) isatu_env("MYSQLPASSWORD", ""),
    ];
    $url = isatu_env("ISATU_DATABASE_URL");
    if ($url !== null) {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts["host"]) || !in_array(strtolower((string) ($parts["scheme"] ?? "")), ["mysql", "mariadb"], true)) {
            throw new RuntimeException("ISATU_DATABASE_URL must look like mysql://user:password@host:port/database.");
        }
        $settings["host"] = trim((string) $parts["host"], "[]");
        $settings["port"] = (int) ($parts["port"] ?? 3306);
        $settings["user"] = rawurldecode((string) ($parts["user"] ?? ""));
        $settings["password"] = rawurldecode((string) ($parts["pass"] ?? ""));
        $database = rawurldecode(trim((string) ($parts["path"] ?? ""), "/"));
        if ($database !== "") {
            $settings["name"] = $database;
        }
    }
    return [
        "host" => (string) isatu_env("ISATU_DB_HOST", $settings["host"]),
        "port" => (int) isatu_env("ISATU_DB_PORT", (string) $settings["port"]),
        "name" => (string) isatu_env("ISATU_DB_NAME", $settings["name"]),
        "user" => (string) isatu_env("ISATU_DB_USER", $settings["user"]),
        "password" => (string) isatu_env("ISATU_DB_PASSWORD", $settings["password"]),
    ];
}

/**
 * True for addresses that never belong to a visitor on the internet: private, reserved, and
 * the 100.64.0.0/10 range hosting providers use between their own machines.
 */
function isatu_is_internal_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return true;
    }
    $long = ip2long($ip);
    return $long !== false && ($long & 0xFFC00000) === (ip2long("100.64.0.0") & 0xFFC00000);
}

/**
 * The visitor's own address from an X-Forwarded-For header. Proxies append the address
 * that connected to them, so the trustworthy entries are on the right: the first public
 * address from the right is the visitor (internal ones are the host's own hops), and
 * anything a visitor writes into the header themselves stays further left.
 */
function isatu_forwarded_client_ip(string $forwardedFor): ?string
{
    $entries = array_values(array_filter(array_map("trim", explode(",", $forwardedFor)), "strlen"));
    $lastValid = null;
    for ($index = count($entries) - 1; $index >= 0; $index--) {
        $candidate = $entries[$index];
        if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
            continue;
        }
        $lastValid = $lastValid ?? $candidate;
        if (!isatu_is_internal_ip($candidate)) {
            return $candidate;
        }
    }
    return $lastValid;
}

/**
 * Behind a hosting proxy (Railway, a load balancer) every request seems to come from the
 * proxy. With ISATU_TRUST_PROXY=1 the visitor's real address and HTTPS are taken from the
 * proxy's headers, so sign-in lockouts, rate limits, and audit logs tell people apart.
 * Enable it only when every request passes through that proxy; otherwise anyone could
 * claim any address.
 */
function isatu_apply_trusted_proxy(): void
{
    static $applied = false;
    if ($applied || PHP_SAPI === "cli" || !isatu_env_flag("ISATU_TRUST_PROXY")) {
        return;
    }
    $applied = true;
    $client = isatu_forwarded_client_ip((string) ($_SERVER["HTTP_X_FORWARDED_FOR"] ?? ""));
    if ($client !== null) {
        $_SERVER["ISATU_PROXY_ADDR"] = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
        $_SERVER["REMOTE_ADDR"] = $client;
    }
    $protocol = strtolower(trim(explode(",", (string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ""))[0]));
    if ($protocol === "https") {
        $_SERVER["HTTPS"] = "on";
        $_SERVER["SERVER_PORT"] = "443";
    }
}

isatu_apply_trusted_proxy();

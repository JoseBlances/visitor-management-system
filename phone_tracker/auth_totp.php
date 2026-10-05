<?php

/*
 * Two-step verification with an authenticator app (TOTP, RFC 6238: 6 digits, 30 s,
 * HMAC-SHA1 — what Google and Microsoft Authenticator use).
 *
 * Secrets are stored encrypted (AES-256-GCM) with a key kept in
 * config/auth_secret.php, which is generated on first use and never committed.
 * If that file is lost, authenticator codes stop working but backup codes still do.
 */

require_once __DIR__ . "/runtime.php";
require_once __DIR__ . "/auth_config.php";

const AUTH_BASE32_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
const AUTH_TOTP_PERIOD = 30;
const AUTH_TOTP_DIGITS = 6;

function auth_base32_encode(string $data): string
{
    $bits = "";
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, "0", STR_PAD_LEFT);
    }
    $output = "";
    foreach (str_split($bits, 5) as $chunk) {
        $output .= AUTH_BASE32_ALPHABET[bindec(str_pad($chunk, 5, "0", STR_PAD_RIGHT))];
    }
    return $output;
}

function auth_base32_decode(string $data): string
{
    $data = strtoupper(preg_replace('/[\s=-]+/', "", $data));
    if ($data === "" || strspn($data, AUTH_BASE32_ALPHABET) !== strlen($data)) {
        return "";
    }
    $bits = "";
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(strpos(AUTH_BASE32_ALPHABET, $char)), 5, "0", STR_PAD_LEFT);
    }
    $output = "";
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $output .= chr(bindec($byte));
        }
    }
    return $output;
}

function auth_totp_generate_secret(): string
{
    return auth_base32_encode(random_bytes(20));
}

/** Groups a secret as "ABCD EFGH ..." so it is easier to type into an app. */
function auth_totp_format_secret(string $secret): string
{
    return trim(chunk_split($secret, 4, " "));
}

function auth_totp_code(string $key, int $step): string
{
    $hash = hash_hmac("sha1", pack("J", $step), $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** AUTH_TOTP_DIGITS)), AUTH_TOTP_DIGITS, "0", STR_PAD_LEFT);
}

/**
 * Accepts the current code and one step either side (clock drift). A step at or
 * before $lastUsedStep is refused so a code cannot be replayed.
 *
 * @return int|null the matching time step
 */
function auth_totp_verify(string $secret, string $code, ?int $lastUsedStep, int $window = 1, ?int $now = null): ?int
{
    $code = preg_replace('/\s+/', "", $code);
    if (!preg_match('/^\d{' . AUTH_TOTP_DIGITS . '}$/', $code)) {
        return null;
    }
    $key = auth_base32_decode($secret);
    if ($key === "") {
        return null;
    }
    $current = intdiv($now ?? time(), AUTH_TOTP_PERIOD);
    for ($offset = -$window; $offset <= $window; $offset++) {
        $step = $current + $offset;
        if ($lastUsedStep !== null && $step <= $lastUsedStep) {
            continue;
        }
        if (hash_equals(auth_totp_code($key, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function auth_totp_uri(string $secret, string $accountName): string
{
    $label = rawurlencode(AUTH_TOTP_ISSUER) . ":" . rawurlencode($accountName);
    return "otpauth://totp/" . $label . "?" . http_build_query([
        "secret" => $secret,
        "issuer" => AUTH_TOTP_ISSUER,
        "algorithm" => "SHA1",
        "digits" => AUTH_TOTP_DIGITS,
        "period" => AUTH_TOTP_PERIOD,
    ], "", "&", PHP_QUERY_RFC3986);
}

function auth_secret_key(): string
{
    static $key = null;
    if (is_string($key)) {
        return $key;
    }
    // A hosted server sets the key directly (64 hexadecimal characters), so redeploying can
    // never lose it. Without it, the key lives in a file (ISATU_AUTH_SECRET_FILE, by default
    // config/auth_secret.php), created on first use.
    $fromEnvironment = strtolower(trim((string) isatu_env("ISATU_AUTH_SECRET", "")));
    if ($fromEnvironment !== "") {
        if (!preg_match('/^[a-f0-9]{64}$/', $fromEnvironment)) {
            throw new RuntimeException("ISATU_AUTH_SECRET must be 64 hexadecimal characters.");
        }
        $key = hex2bin($fromEnvironment);
        return $key;
    }
    $file = (string) isatu_env("ISATU_AUTH_SECRET_FILE", __DIR__ . "/config/auth_secret.php");
    if (!is_file($file)) {
        $contents = "<?php\n"
            . "// Generated automatically for two-step verification. Keep it private, back it up\n"
            . "// with the server, and never commit it. Losing it disables existing authenticator\n"
            . "// setups (backup codes keep working).\n"
            . "return '" . bin2hex(random_bytes(32)) . "';\n";
        $temporary = $file . "." . bin2hex(random_bytes(4)) . ".tmp";
        if (file_put_contents($temporary, $contents, LOCK_EX) !== false) {
            // Another request may have created the key first; keep whichever exists.
            if (is_file($file) || !@rename($temporary, $file)) {
                @unlink($temporary);
            }
        }
    }
    $value = is_file($file) ? require $file : null;
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/', $value)) {
        throw new RuntimeException("The two-step verification key (phone_tracker/config/auth_secret.php) is missing or invalid.");
    }
    $key = hex2bin($value);
    return $key;
}

function auth_encrypt_secret(string $plain, int $userId): string
{
    $iv = random_bytes(12);
    $tag = "";
    $cipher = openssl_encrypt($plain, "aes-256-gcm", auth_secret_key(), OPENSSL_RAW_DATA, $iv, $tag, "user:" . $userId, 16);
    if ($cipher === false) {
        throw new RuntimeException("Could not encrypt the two-step verification secret.");
    }
    return "v1:" . base64_encode($iv . $tag . $cipher);
}

function auth_decrypt_secret(?string $stored, int $userId): ?string
{
    if (!is_string($stored) || strncmp($stored, "v1:", 3) !== 0) {
        return null;
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= 28) {
        return null;
    }
    try {
        $key = auth_secret_key();
    } catch (Throwable $error) {
        error_log("Two-step verification: " . $error->getMessage());
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), "aes-256-gcm", $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), "user:" . $userId);
    if ($plain === false) {
        error_log("Two-step verification: the secret for user " . $userId . " could not be decrypted (was config/auth_secret.php replaced?).");
        return null;
    }
    return $plain;
}

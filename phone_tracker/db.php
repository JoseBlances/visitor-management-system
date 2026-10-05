<?php
require_once __DIR__ . "/runtime.php";

/**
 * Opens the database named by isatu_db_settings() (runtime.php): the hosted server's
 * settings, or local XAMPP when none are set.
 */
function isatu_db_connect(): mysqli
{
    try {
        $db = isatu_db_settings();
        $connection = new mysqli($db["host"], $db["user"], $db["password"], $db["name"], $db["port"]);
        if ($connection->connect_error) {
            throw new RuntimeException($connection->connect_error);
        }
        $connection->set_charset("utf8mb4");
        // Philippine time, and the same text comparison rules as the tables whatever the
        // server's default (MariaDB 11.5 and later changed it).
        $connection->query("SET time_zone = '+08:00', collation_connection = 'utf8mb4_general_ci'");
        return $connection;
    } catch (Throwable $error) {
        if (PHP_SAPI === "cli") {
            fwrite(STDERR, "Database connection failed: " . $error->getMessage() . "\n");
            exit(1);
        }
        // Log the details for the administrator; never show them to visitors.
        error_log("Database connection failed: " . $error->getMessage());
        if (!headers_sent()) {
            http_response_code(503);
            header("Content-Type: application/json; charset=utf-8");
            header("Retry-After: 30");
        }
        echo json_encode([
            "success" => false,
            "message" => "The service is temporarily unavailable. Please try again in a moment.",
        ]);
        exit;
    }
}

$conn = isatu_db_connect();

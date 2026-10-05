<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * Sends the visitor emails waiting in outbound_emails (mail_service.php).
 *   php workers/send_outbound_emails.php                    send what is due
 *   php workers/send_outbound_emails.php --test you@isatu   send one test email now
 * Exit code 2 means email is not set up yet; queued emails wait until it is.
 */

require_once dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/mail_service.php";

$settings = mail_settings();
$problem = mail_configuration_problem($settings);
if ($problem !== null) {
    fwrite(STDERR, $problem . "\n");
    exit(2);
}

$testIndex = array_search("--test", $argv, true);
if ($testIndex !== false) {
    $to = strtolower(trim((string) ($argv[$testIndex + 1] ?? "")));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Usage: php workers/send_outbound_emails.php --test you@example.com\n");
        exit(1);
    }
    $text = "This is a test from ISATU Visitor Management. Email is working: visitors will receive their recovery codes.\n";
    $html = "<p>This is a test from ISATU Visitor Management.</p><p>Email is working: visitors will receive their recovery codes.</p>";
    try {
        $messageId = mail_send($settings, $to, "", "ISATU Visitor Management test email", $text, $html);
    } catch (Throwable $error) {
        fwrite(STDERR, "The test email was not sent through " . $settings["transport"] . ": " . $error->getMessage() . "\n");
        exit(1);
    }
    echo "Test email accepted by " . $settings["transport"] . " for " . $to . ($messageId !== "" ? " (message " . $messageId . ")" : "") . ".\n";
    exit(0);
}

try {
    $counts = mail_process_queue($conn, $settings);
} catch (mysqli_sql_exception $error) {
    fwrite(STDERR, "The email queue could not be read: " . $error->getMessage() . ". Import mobile_api_migration.sql.\n");
    exit(1);
}
echo "Email worker complete: {$counts['sent']} sent, {$counts['failed']} failed, {$counts['expired']} expired.\n";

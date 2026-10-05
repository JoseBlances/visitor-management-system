<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * Applies the time-based visit rules (appointment slots that ended, confirmed campus exits,
 * end of day) even while nobody has a dashboard open. Pages apply the same rules when they
 * load, so running this more often never changes a record twice.
 */

require_once dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/appointment_maintenance.php";

refresh_appointment_time_states($conn);
echo "Visit timing is up to date.\n";

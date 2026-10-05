#!/bin/sh
# Starts the web server container (DEPLOYMENT.md): prepares the persistent folders, creates
# or updates the database, prints the readiness report, starts the background jobs, and
# runs Apache in the foreground.
set -eu

export PORT="${PORT:-8080}"
DATA="${ISATU_DATA_DIR:-/data}"
APP=/var/www/html/phone_tracker
PHP=/usr/local/bin/php

# Persistent folders. With a Railway volume mounted at /data they survive every deploy:
# sign-in sessions, staff profile photos, and (unless ISATU_AUTH_SECRET is set) the
# two-step verification key.
mkdir -p "$DATA/sessions" "$DATA/uploads/profile_pictures" "$DATA/private"
cp -f /usr/local/share/isatu-uploads/profile_pictures/.htaccess "$DATA/uploads/profile_pictures/.htaccess"
chown -R www-data:www-data "$DATA/sessions" "$DATA/uploads" "$DATA/private"
chmod 700 "$DATA/sessions" "$DATA/private"
if [ -z "${ISATU_AUTH_SECRET:-}" ] && [ -z "${ISATU_AUTH_SECRET_FILE:-}" ]; then
    export ISATU_AUTH_SECRET_FILE="$DATA/private/auth_secret.php"
fi

# Everything below runs as the web server's user, never as root.
as_web() {
    runuser -u www-data -- "$@"
}

# Create or update the database tables (waits for the database to start).
if ! as_web "$PHP" "$APP/tools/setup_database.php" --wait --secure-default-accounts; then
    echo "The database is not ready, so the site will report an error until it is. Check the settings above, then redeploy." >&2
fi
as_web "$PHP" "$APP/tools/preflight.php" || true

# Background jobs (emails, push notifications, visit timing, history cleanup), restarted
# if they ever stop.
(
    while true; do
        as_web "$PHP" "$APP/workers/run_scheduled_jobs.php" || true
        sleep 10
    done
) &

exec apache2-foreground

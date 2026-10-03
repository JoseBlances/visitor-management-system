# Accountability: names, departments, and user activity

Every action in the system can be traced to a real person. Database changes are in
`phone_tracker/personnel_directory_migration.sql` (import after `auth_security_migration.sql`;
safe to re-run).

## Real names on every account

- Accounts have a **first name**, **last name**, and optional **position**, **contact number**,
  and **email**. `display_name` is kept as "First Last" so older screens keep working.
- Only administrators set or change names (**User Management → Manage → Edit details**).
  Staff can change their photo but not their name, so nobody can hide behind a nickname.
- Accounts without a real name show **No name on file** in the user list, the activity
  log, and the department board. The seeded accounts (`admin`, `security`, `offices`)
  need their owners' names added.
- **Add user** suggests a username from the name (Ma. Theresa Dela Cruz → `mdelacruz`).

## Department directory (Office Personnel)

**User Management → Department Directory** lists every department with its personnel,
requests waiting for approval, open appointments, whether it accepts visitors, and
whether it has a campus-map pin.

- **Add department:** a name, a permanent short code (suggested, e.g. `REGISTRARS`), an
  optional location and description. It immediately appears in the visitor app, the
  booking page, account forms, and the campus map editor.
- **Archive:** stops new bookings and new personnel; history keeps the department name.
  **Restore** reopens it. **Delete** is only possible for a department that was never used.
- Moving a person to another department signs them out so the new access applies.
- Archiving, deleting, and moving people ask the administrator to confirm their identity.

The department list lives in the `offices` table; `appointment_office_map()` returns all
departments (for labels) and `appointment_office_active_map()` the ones people can choose.

## User activity

**Admin Dashboard → User Activity** shows the latest actions with the person's name and
department, with quick choices (sign-ins, approvals and declines, gate and visits, office
schedules, accounts) and a department filter.

**Security → User activity** is the full log:

- Filter by period, department, person, type of activity, or search a visitor, person, or
  appointment number. Counts per type update with the filters.
- **Personnel by Department:** everyone in each department, who is online now, when they
  last signed in, and what they did in the period (approved, declined, checked in, …).
- **Person view:** contact details, devices and networks used in the last 30 days (with a
  warning when an account looks shared), counts, and their recent activity.
- **Appointment history:** who approved, declined, checked in, and ended a visit, plus
  every recorded step with times and network addresses. Open it from **History** in
  Recent Visitors or from any "Appointment #" in the log.
- **Export CSV** downloads the filtered log (up to 5,000 rows); the export itself is
  recorded in the log.

Activity comes from `audit_logs`, written by the existing endpoints. Plain-language text
is built in `phone_tracker/activity_service.php`, so the screen and the CSV always match.
When you add an endpoint that changes data, write an `audit_logs` row with the acting
user and add a `case` for its action in `activity_describe()`.

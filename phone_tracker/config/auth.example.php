<?php
// Copy this file to auth.php to change sign-in settings on one machine only.
// Never commit auth.php.
return [
    // Roles that must use two-step verification at sign-in. The deployed server must
    // keep "admin". A development machine may use [] to skip the authenticator step.
    "two_factor_required_roles" => ["admin"],

    // Minutes without any activity before someone is signed out. 0 = never: people stay
    // signed in until they sign out or session_max_hours is reached.
    "idle_timeout_minutes" => 0,

    // Everyone signs in again this many hours after signing in (one shift).
    "session_max_hours" => 12,
];

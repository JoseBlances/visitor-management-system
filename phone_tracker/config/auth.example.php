<?php
// Copy this file to auth.php to change sign-in settings on one machine only.
// Never commit auth.php.
return [
    // Roles that must use two-step verification at sign-in. The deployed server must
    // keep "admin". A development machine may use [] to skip the authenticator step.
    "two_factor_required_roles" => ["admin"],
];

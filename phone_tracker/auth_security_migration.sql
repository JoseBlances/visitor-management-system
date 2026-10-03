-- Authentication hardening: sign-in lockouts and history, temporary passwords,
-- two-step verification (authenticator app), backup codes, trusted devices, and
-- server-side session revocation. Import after the other migrations.
-- Safe to run repeatedly on the MariaDB used by XAMPP.

SET NAMES utf8mb4;

ALTER TABLE `app_users`
  ADD COLUMN IF NOT EXISTS `must_change_password` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `temp_password_expires_at` datetime DEFAULT NULL AFTER `must_change_password`,
  ADD COLUMN IF NOT EXISTS `session_version` int(10) unsigned NOT NULL DEFAULT 1 AFTER `temp_password_expires_at`,
  ADD COLUMN IF NOT EXISTS `totp_secret` varchar(255) DEFAULT NULL AFTER `session_version`,
  ADD COLUMN IF NOT EXISTS `totp_enabled_at` datetime DEFAULT NULL AFTER `totp_secret`,
  ADD COLUMN IF NOT EXISTS `totp_last_used_step` bigint(20) DEFAULT NULL AFTER `totp_enabled_at`,
  ADD COLUMN IF NOT EXISTS `last_login_at` datetime DEFAULT NULL AFTER `totp_last_used_step`,
  ADD COLUMN IF NOT EXISTS `last_login_ip` varchar(45) DEFAULT NULL AFTER `last_login_at`;

-- One row per sign-in attempt (website and mobile). Feeds the lockout rules and the
-- admin's sign-in history. Unknown usernames are stored masked.
CREATE TABLE IF NOT EXISTS `auth_login_attempts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `subject_key` char(64) NOT NULL,
  `identifier` varchar(190) NOT NULL DEFAULT '',
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `channel` enum('web','mobile') NOT NULL DEFAULT 'web',
  `stage` enum('password','two_factor','step_up') NOT NULL DEFAULT 'password',
  `outcome` enum('success','failure') NOT NULL,
  `reason` varchar(40) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attempt_subject_ip` (`subject_key`, `ip_address`, `id`),
  KEY `idx_attempt_subject` (`subject_key`, `created_at`),
  KEY `idx_attempt_ip` (`ip_address`, `created_at`),
  KEY `idx_attempt_user` (`user_id`, `created_at`),
  KEY `idx_attempt_created` (`created_at`),
  CONSTRAINT `fk_attempt_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Active and past lockouts. scope: account_device = one account from one IP,
-- account = one account from everywhere, ip = every account from one IP.
CREATE TABLE IF NOT EXISTS `auth_lockouts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `scope` enum('account_device','account','ip') NOT NULL,
  `subject_key` char(64) NOT NULL DEFAULT '',
  `user_id` int(11) DEFAULT NULL,
  `identifier` varchar(190) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `level` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `failure_count` int(10) unsigned NOT NULL DEFAULT 0,
  `trigger_attempt_id` bigint(20) NOT NULL DEFAULT 0,
  `blocked_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime NOT NULL,
  `released_at` datetime DEFAULT NULL,
  `released_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lockout_subject` (`scope`, `subject_key`, `ip_address`, `locked_until`),
  KEY `idx_lockout_ip` (`scope`, `ip_address`, `locked_until`),
  KEY `idx_lockout_active` (`released_at`, `locked_until`),
  KEY `idx_lockout_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_lockout_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_lockout_released_by` FOREIGN KEY (`released_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One-time recovery codes for two-step verification (bcrypt hashes only).
CREATE TABLE IF NOT EXISTS `user_backup_codes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_backup_user` (`user_id`, `used_at`),
  CONSTRAINT `fk_backup_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Browsers where the user chose to skip two-step verification for 30 days.
-- Only a SHA-256 hash of the cookie token is stored.
CREATE TABLE IF NOT EXISTS `user_trusted_devices` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `label` varchar(120) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trusted_token` (`token_hash`),
  KEY `idx_trusted_user` (`user_id`, `expires_at`),
  CONSTRAINT `fk_trusted_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The seeded staff accounts share the published password "password". Force a new
-- password at their next sign-in. Accounts that already changed it are not affected.
UPDATE `app_users`
SET `must_change_password` = 1
WHERE `role` IN ('admin', 'security', 'offices')
  AND `password_hash` = '$2y$10$joZ8DFdUSvotBXFFQhPQkO0uyZthbNB3.mVREmRbhIeGRNp7CngKe'
  AND `must_change_password` = 0;

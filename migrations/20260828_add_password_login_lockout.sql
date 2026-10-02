-- SecurePOS Security Step 24: temporary lockout for Email + Password login only.
ALTER TABLE users
    ADD COLUMN failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER account_status,
    ADD COLUMN locked_until DATETIME NULL DEFAULT NULL AFTER failed_login_attempts;

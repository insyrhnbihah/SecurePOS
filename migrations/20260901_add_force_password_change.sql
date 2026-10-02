-- SecurePOS Manager-assisted password recovery: require a password change
-- after a Manager issues a temporary password. Existing users remain at 0.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER locked_until;

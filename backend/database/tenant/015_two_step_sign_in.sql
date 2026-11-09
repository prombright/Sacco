-- Two-step sign-in: a six-digit code from an authenticator app after the password, and one-time
-- recovery codes (stored hashed) for a lost phone.
ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(64) NULL AFTER password_changed_at,
    ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret,
    ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_enabled_at,
    ADD COLUMN totp_recovery TEXT NULL AFTER totp_last_step;

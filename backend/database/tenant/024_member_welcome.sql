-- When a member saw the first-sign-in welcome (so it shows once, on any phone or computer)
ALTER TABLE users ADD COLUMN welcomed_at DATETIME NULL AFTER last_login_at;

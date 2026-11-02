-- A SACCO's database: who can sign in, and the logs that go with signing in.

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(160) NOT NULL,
    -- They sign in with either of these
    email VARCHAR(190) NULL,
    employee_no VARCHAR(40) NULL,
    -- The SACCO account number payroll files are matched on
    account_no VARCHAR(40) NULL,
    phone VARCHAR(30) NULL,
    role ENUM('super_admin', 'admin', 'member') NOT NULL DEFAULT 'member',
    status ENUM('active', 'suspended', 'left') NOT NULL DEFAULT 'active',
    -- manual = added by an admin; api = brought in from the staff API (external_id is its id there)
    source ENUM('manual', 'api') NOT NULL DEFAULT 'manual',
    external_id VARCHAR(64) NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    password_changed_at DATETIME NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_employee_no (employee_no),
    UNIQUE KEY uq_users_account_no (account_no),
    UNIQUE KEY uq_users_external_id (external_id),
    KEY idx_users_name (full_name),
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Earlier passwords, so a user can't go back to a recent one
CREATE TABLE password_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_password_history_user (user_id),
    CONSTRAINT fk_password_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every sign-in attempt, successful or not
CREATE TABLE login_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    login VARCHAR(190) NOT NULL,
    success TINYINT(1) NOT NULL,
    reason VARCHAR(40) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_log_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who did what. Rows are only ever added, never changed or removed.
CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    subject_type VARCHAR(40) NULL,
    subject_id INT UNSIGNED NULL,
    details JSON NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_subject (subject_type, subject_id),
    KEY idx_audit_actor (actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SACCO rules that admins can change (interest rate, loan multiplier...), as key/value
CREATE TABLE settings (
    `key` VARCHAR(60) NOT NULL PRIMARY KEY,
    `value` VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
    ('loan_interest_rate_monthly', '1.0'),
    ('loan_interest_method', 'reducing_balance'),
    ('loan_savings_multiplier', '3');

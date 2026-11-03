-- The developer portal: the people who run this installation and look after every SACCO on it
CREATE TABLE developers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_developers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change made from the developer portal, to any SACCO. Rows are only ever added.
CREATE TABLE platform_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    developer_id INT UNSIGNED NULL,
    sacco_id INT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    details TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_platform_audit_sacco (sacco_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

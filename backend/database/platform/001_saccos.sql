-- The platform database: one row per SACCO on this installation. Each SACCO's own data lives in
-- its own database (db_name), set up from database/tenant/.

CREATE TABLE saccos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    short_name VARCHAR(40) NULL,
    domain VARCHAR(190) NULL,
    db_name VARCHAR(64) NOT NULL,
    logo_url VARCHAR(255) NULL,
    brand_color CHAR(7) NOT NULL DEFAULT '#15803d',
    currency CHAR(3) NOT NULL DEFAULT 'UGX',
    status ENUM('active', 'paused') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_saccos_code (code),
    UNIQUE KEY uq_saccos_domain (domain),
    UNIQUE KEY uq_saccos_db (db_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

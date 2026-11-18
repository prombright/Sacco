-- Two people for big money: requests at or above the limit wait here for a second office person
CREATE TABLE approvals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(30) NOT NULL,
    area VARCHAR(20) NOT NULL,
    handler VARCHAR(190) NOT NULL,
    method VARCHAR(8) NOT NULL,
    path VARCHAR(190) NOT NULL,
    params TEXT NOT NULL,
    body MEDIUMTEXT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    summary VARCHAR(255) NOT NULL,
    member_id INT UNSIGNED NULL,
    status ENUM('pending', 'approving', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    requested_by INT UNSIGNED NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    note VARCHAR(255) NULL,
    KEY idx_approvals_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Off until the super admin sets a limit (Setup)
INSERT IGNORE INTO settings (`key`, value) VALUES ('approval_limit', '0');

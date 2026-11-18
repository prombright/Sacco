-- Arrears follow-up: what the office did about a late loan (notes, promises to pay, reminders,
-- guarantors told)
CREATE TABLE loan_followups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    kind ENUM('note', 'promise', 'reminder', 'guarantors') NOT NULL,
    note VARCHAR(500) NULL,
    promise_date DATE NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_followups_loan (loan_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guarantors told automatically after this many days late: off until the committee decides
INSERT IGNORE INTO settings (`key`, value) VALUES ('guarantor_warning_days', '0');

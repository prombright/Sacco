-- Credit standing, payment reminders and profile photos.
-- cleared_on: the day an instalment was fully paid, to tell on-time payers from slow ones.
ALTER TABLE loan_installments ADD COLUMN cleared_on DATE NULL AFTER interest_paid;

-- photo: a version token while the member has a photo (the file is storage/photos/<sacco>/<id>.jpg);
-- credit_grade: the last standing worked out, so the office hears only when it changes.
ALTER TABLE users
    ADD COLUMN photo VARCHAR(20) NULL AFTER address,
    ADD COLUMN credit_grade ENUM('new', 'good', 'fair', 'poor') NULL AFTER photo;

-- One reminder of each kind per instalment
CREATE TABLE loan_reminders (
    installment_id INT UNSIGNED NOT NULL,
    kind ENUM('due_soon', 'overdue') NOT NULL,
    sent_on DATE NOT NULL,
    PRIMARY KEY (installment_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (`key`, value) VALUES ('loan_reminder_days', '3'), ('slow_payer_limit_percent', '50');

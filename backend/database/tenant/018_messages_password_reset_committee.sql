-- Messages by email, password resets and the committee's decisions.

-- Each message to a member can also go to their email: queued, being sent, sent or failed
ALTER TABLE notifications
    ADD COLUMN email_status ENUM('none', 'queued', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'none' AFTER read_at,
    ADD COLUMN email_claim CHAR(16) NULL AFTER email_status,
    ADD COLUMN email_tries TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER email_claim,
    ADD COLUMN emailed_at DATETIME NULL AFTER email_tries,
    ADD COLUMN email_error VARCHAR(255) NULL AFTER emailed_at,
    ADD INDEX idx_notifications_email (email_status, id);

-- Members can turn the email copies off
ALTER TABLE users ADD COLUMN email_alerts TINYINT(1) NOT NULL DEFAULT 1 AFTER email;

-- "Forgot password" links: only a hash of the token is kept; each works once, for an hour
-- (user_id is empty for an email that isn't on any account: still counted, to slow down guessing)
CREATE TABLE password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    ip VARCHAR(45) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user (user_id, created_at),
    INDEX idx_password_resets_ip (ip, created_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Questions waiting for the committee, what the system does meanwhile, and what was decided
CREATE TABLE committee_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(120) NOT NULL,
    question TEXT NOT NULL,
    setting_key VARCHAR(60) NULL,
    status ENUM('open', 'decided') NOT NULL DEFAULT 'open',
    decision TEXT NULL,
    decided_value VARCHAR(255) NULL,
    meeting VARCHAR(160) NULL,
    decided_on DATE NULL,
    decided_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO committee_questions (topic, question, setting_key) VALUES
('Membership fee', 'Is the membership fee UGX 25,000 a year, with no joining fee?', 'membership_fee_annual'),
('Late payers'' borrowing limit', 'How far should a member''s borrowing limit drop as their repayment record gets worse? The system lowers it step by step below a score of 80, down to the lowest limit set here for the worst payers.', 'slow_payer_limit_percent'),
('How much members can borrow', 'How many times their savings can a member borrow? (Set for each loan type on Setup, under Loan types.)', NULL),
('Loan interest', 'Is loan interest 1% a month on the reducing balance (not a flat rate)? (Set for each loan type on Setup, under Loan types.)', NULL),
('Guarantors', 'How many guarantors does a loan need, and must they cover the whole loan? (Set for each loan type on Setup, under Loan types.)', NULL),
('Late-payment penalty', 'Should late repayments carry a penalty, and how much (% of what is late)?', 'loan_penalty_percent'),
('Dividend', 'What dividend should be paid each year, as a % of each member''s share capital?', 'dividend_rate_percent'),
('Sharing investment profit', 'Should profit from investments be shared by each member''s average savings over the year, or by their savings at the end of the year?', 'profit_basis');

INSERT IGNORE INTO settings (`key`, value) VALUES ('credit_limit_rule', 'sliding');

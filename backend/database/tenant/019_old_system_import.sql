-- Bringing a SACCO in from its old accounting system: the accounts its books need, what members
-- were owed as savings interest, and the old transactions kept for reference.

INSERT IGNORE INTO gl_accounts (code, name, type, system_key, description) VALUES
    ('1020', 'Funds in transit', 'asset', 'funds_in_transit', 'Money that should have reached the bank but has not arrived yet'),
    ('1130', 'Loan fees receivable', 'asset', NULL, 'Loan processing fees charged and not yet paid'),
    ('1300', 'Projects', 'asset', NULL, 'Money put into SACCO projects'),
    ('2010', 'Savings interest payable', 'liability', 'savings_interest_payable', 'Savings interest members have earned and not yet been paid');

-- One line per member: what the old system owed them in savings interest. It stays as a payable
-- until the committee decides how to settle it, and can be negative where too much was paid.
CREATE TABLE old_system_interest_payable (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    amount DECIMAL(15, 2) NOT NULL,
    as_at DATE NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_old_payable_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The old system's member ledger, read-only. It never affects a balance: those start from the
-- opening balances. user_id is NULL for people who had nothing left and so have no account here.
CREATE TABLE old_system_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    old_code VARCHAR(20) NOT NULL,
    entry_date DATE NOT NULL,
    details VARCHAR(255) NULL,
    voucher VARCHAR(60) NULL,
    savings DECIMAL(15, 2) NOT NULL DEFAULT 0,
    shares DECIMAL(15, 2) NOT NULL DEFAULT 0,
    loan DECIMAL(15, 2) NOT NULL DEFAULT 0,
    loan_interest DECIMAL(15, 2) NOT NULL DEFAULT 0,
    savings_interest DECIMAL(15, 2) NOT NULL DEFAULT 0,
    other DECIMAL(15, 2) NOT NULL DEFAULT 0,
    KEY idx_old_history_user (user_id, entry_date),
    KEY idx_old_history_code (old_code),
    CONSTRAINT fk_old_history_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The committee's rule: members borrow up to twice their savings
UPDATE settings SET `value` = '2' WHERE `key` = 'loan_savings_multiplier';

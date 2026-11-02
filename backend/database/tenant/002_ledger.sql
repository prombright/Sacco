-- The ledger: every change to a member's savings or shares, one row each. Rows are only ever
-- added. A mistake is corrected with a reversal (an equal and opposite row pointing at it), so the
-- history always shows what happened. A balance is the balance_after of the account's latest row.

CREATE TABLE transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account ENUM('savings', 'shares') NOT NULL,
    type ENUM('opening_balance', 'deposit', 'withdrawal', 'adjustment', 'dividend', 'reversal') NOT NULL,
    -- Positive adds to the account, negative takes from it
    amount DECIMAL(15, 2) NOT NULL,
    balance_after DECIMAL(15, 2) NOT NULL,
    -- The day the money actually moved (the payroll month, the cheque date)
    value_date DATE NOT NULL,
    reference VARCHAR(80) NULL,
    narration VARCHAR(255) NULL,
    -- Set on a reversal: the row it cancels
    reversal_of BIGINT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_transactions_account (user_id, account, id),
    KEY idx_transactions_value_date (value_date),
    UNIQUE KEY uq_transactions_reversal_of (reversal_of),
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_transactions_reversal FOREIGN KEY (reversal_of) REFERENCES transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price of one share; 0 until the committee sets it (then share counts are shown)
INSERT INTO settings (`key`, `value`) VALUES ('share_price', '0');

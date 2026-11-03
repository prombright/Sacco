-- Dividends: an admin enters a percentage; every member's share capital as at a date earns it,
-- credited to their shares account. A run can be reversed as a whole, like a payroll upload.

CREATE TABLE dividend_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- e.g. "2026" or "2026 first half"
    label VARCHAR(80) NOT NULL,
    rate_percent DECIMAL(6, 3) NOT NULL,
    -- Share balances as at the end of this day earn the dividend
    record_date DATE NOT NULL,
    -- The day the dividend is credited
    value_date DATE NOT NULL,
    member_count INT UNSIGNED NOT NULL,
    total_shares DECIMAL(15, 2) NOT NULL,
    total_dividend DECIMAL(15, 2) NOT NULL,
    status ENUM('posted', 'reversed') NOT NULL DEFAULT 'posted',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_by INT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reversal_reason VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
    ADD COLUMN dividend_run_id INT UNSIGNED NULL AFTER batch_id,
    ADD KEY idx_transactions_dividend (dividend_run_id),
    ADD CONSTRAINT fk_transactions_dividend FOREIGN KEY (dividend_run_id) REFERENCES dividend_runs (id);

-- Excel uploads of dated transactions (e.g. catching up after the old system's balance date) are
-- kept with the payroll uploads, so a whole upload can be undone the same way. "quiet" uploads
-- send members no messages.
ALTER TABLE payroll_batches
    ADD COLUMN kind ENUM('payroll', 'transactions') NOT NULL DEFAULT 'payroll' AFTER id,
    ADD COLUMN file_hash CHAR(64) NULL AFTER file_name,
    ADD COLUMN total_withdrawn DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER total_savings,
    ADD COLUMN money_account_id INT UNSIGNED NULL AFTER total_loans,
    ADD COLUMN quiet TINYINT(1) NOT NULL DEFAULT 0 AFTER money_account_id,
    ADD COLUMN first_date DATE NULL AFTER quiet,
    ADD COLUMN last_date DATE NULL AFTER first_date,
    ADD KEY idx_payroll_batches_hash (file_hash);

-- Payroll money the employer has deducted but not yet paid into the bank: it can be chosen as where
-- an upload's money sits, and moved to the bank (Cash book) when it arrives
UPDATE gl_accounts SET is_money = 1 WHERE system_key = 'funds_in_transit';

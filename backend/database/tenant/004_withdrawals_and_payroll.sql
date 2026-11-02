-- Savings withdrawal requests (paid by cheque) and monthly payroll uploads.

-- A member asks online; an admin approves, then records the cheque, which is when the money
-- leaves the savings ledger. While pending or approved, the amount is held back from what the
-- member can withdraw or pledge again.
CREATE TABLE withdrawal_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_no VARCHAR(20) NULL,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    reason VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'paid', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(255) NULL,
    cheque_no VARCHAR(80) NULL,
    paid_on DATE NULL,
    paid_by INT UNSIGNED NULL,
    transaction_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_withdrawal_no (request_no),
    KEY idx_withdrawals_user (user_id, status),
    KEY idx_withdrawals_status (status),
    CONSTRAINT fk_withdrawals_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_withdrawals_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One uploaded payroll file: every row's savings, shares and loan repayment posted together
CREATE TABLE payroll_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- The payroll month, e.g. 2026-11
    period CHAR(7) NOT NULL,
    file_name VARCHAR(190) NULL,
    row_count INT UNSIGNED NOT NULL,
    total_savings DECIMAL(15, 2) NOT NULL,
    total_shares DECIMAL(15, 2) NOT NULL,
    total_loans DECIMAL(15, 2) NOT NULL,
    status ENUM('posted', 'reversed') NOT NULL DEFAULT 'posted',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_by INT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reversal_reason VARCHAR(255) NULL,
    KEY idx_payroll_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rows that came from a payroll upload point at it
ALTER TABLE transactions
    ADD COLUMN batch_id INT UNSIGNED NULL AFTER reversal_of,
    ADD KEY idx_transactions_batch (batch_id),
    ADD CONSTRAINT fk_transactions_batch FOREIGN KEY (batch_id) REFERENCES payroll_batches (id);

ALTER TABLE loan_payments
    ADD COLUMN batch_id INT UNSIGNED NULL AFTER reversal_of,
    ADD KEY idx_loan_payments_batch (batch_id),
    ADD CONSTRAINT fk_loan_payments_batch FOREIGN KEY (batch_id) REFERENCES payroll_batches (id);

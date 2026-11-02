-- Loans: the application, its guarantors, its repayment schedule and the repayments made.
--
-- Life of a loan:
--   pending_guarantors -> pending_approval -> approved -> active -> closed
--   (or rejected / cancelled before it is disbursed)

CREATE TABLE loans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_no VARCHAR(20) NULL,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    months SMALLINT UNSIGNED NOT NULL,
    -- Fixed at application, so later changes to the SACCO's rate don't change this loan
    interest_rate_monthly DECIMAL(5, 2) NOT NULL,
    interest_method ENUM('reducing_balance') NOT NULL DEFAULT 'reducing_balance',
    purpose VARCHAR(255) NULL,
    status ENUM('pending_guarantors', 'pending_approval', 'approved', 'active', 'closed', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending_guarantors',
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(255) NULL,
    disbursed_by INT UNSIGNED NULL,
    disbursed_on DATE NULL,
    disbursement_ref VARCHAR(80) NULL,
    closed_on DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loans_no (loan_no),
    KEY idx_loans_user (user_id, status),
    KEY idx_loans_status (status),
    CONSTRAINT fk_loans_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Members standing behind a loan with part of their savings
CREATE TABLE loan_guarantors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    guarantor_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    -- withdrawn = the loan was cancelled or rejected before they answered
    status ENUM('pending', 'accepted', 'declined', 'withdrawn') NOT NULL DEFAULT 'pending',
    responded_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loan_guarantor (loan_id, guarantor_id),
    KEY idx_guarantor (guarantor_id, status),
    CONSTRAINT fk_loan_guarantors_loan FOREIGN KEY (loan_id) REFERENCES loans (id),
    CONSTRAINT fk_loan_guarantors_user FOREIGN KEY (guarantor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equal monthly instalments, made when the loan is disbursed. principal_paid / interest_paid
-- are worked out from loan_payments (Loans::applyPayments) and never typed in.
CREATE TABLE loan_installments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    number SMALLINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    principal DECIMAL(15, 2) NOT NULL,
    interest DECIMAL(15, 2) NOT NULL,
    principal_paid DECIMAL(15, 2) NOT NULL DEFAULT 0,
    interest_paid DECIMAL(15, 2) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_installment (loan_id, number),
    KEY idx_installments_due (due_date),
    CONSTRAINT fk_installments_loan FOREIGN KEY (loan_id) REFERENCES loans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repayments. Like the savings ledger, rows are only added; a mistake is a reversal row.
CREATE TABLE loan_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    paid_on DATE NOT NULL,
    reference VARCHAR(80) NULL,
    narration VARCHAR(255) NULL,
    reversal_of BIGINT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_loan_payments_loan (loan_id, paid_on),
    UNIQUE KEY uq_loan_payments_reversal (reversal_of),
    CONSTRAINT fk_loan_payments_loan FOREIGN KEY (loan_id) REFERENCES loans (id),
    CONSTRAINT fk_loan_payments_reversal FOREIGN KEY (reversal_of) REFERENCES loan_payments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
    ('loan_max_months', '24'),
    ('loan_min_guarantors', '2'),
    -- Guarantors' pledges together must cover this % of the loan
    ('loan_guarantee_cover_percent', '100');

-- Loan security (collateral): what a borrower leaves with the SACCO besides guarantors, such as a
-- land title, a car logbook or household items. Recorded by the office when giving the loan, and
-- marked returned once the loan is repaid.
CREATE TABLE IF NOT EXISTS loan_securities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    kind VARCHAR(40) NOT NULL,
    description VARCHAR(255) NOT NULL,
    -- Estimated value, if known
    value DECIMAL(14, 2) NULL,
    -- Title / logbook / serial number
    reference VARCHAR(80) NULL,
    -- Where it is kept (e.g. "SACCO safe", "With the treasurer")
    kept_at VARCHAR(120) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    returned_on DATE NULL,
    returned_by INT UNSIGNED NULL,
    KEY idx_loan_securities_loan (loan_id),
    CONSTRAINT fk_loan_securities_loan FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

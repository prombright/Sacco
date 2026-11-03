-- What a full SACCO system needs beyond the basics: member details and next of kin, loan
-- products with fees, penalties on late loans, early settlement, the SACCO's own income and
-- expenses, closing a membership, and notices to members.

-- Member details (KYC)
ALTER TABLE users
    ADD COLUMN national_id VARCHAR(30) NULL AFTER phone,
    ADD COLUMN date_of_birth DATE NULL AFTER national_id,
    ADD COLUMN gender ENUM('female', 'male') NULL AFTER date_of_birth,
    ADD COLUMN department VARCHAR(80) NULL AFTER gender,
    ADD COLUMN position VARCHAR(80) NULL AFTER department,
    ADD COLUMN address VARCHAR(255) NULL AFTER position;

-- Who receives a member's savings and shares if they die
CREATE TABLE next_of_kin (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    relationship VARCHAR(40) NOT NULL,
    phone VARCHAR(30) NULL,
    share_percent DECIMAL(5, 2) NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_next_of_kin_user (user_id),
    CONSTRAINT fk_next_of_kin_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Loan products: each kind of loan with its own terms. A loan keeps the terms it was given.
CREATE TABLE loan_products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    interest_rate_monthly DECIMAL(5, 2) NOT NULL,
    max_months SMALLINT UNSIGNED NOT NULL,
    savings_multiplier DECIMAL(5, 2) NOT NULL,
    -- Taken from the payout when the loan is disbursed
    processing_fee_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    min_guarantors TINYINT UNSIGNED NOT NULL,
    guarantee_cover_percent SMALLINT UNSIGNED NOT NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The one loan the SACCO had so far becomes its first product, with the rules already set
INSERT INTO loan_products (name, description, interest_rate_monthly, max_months, savings_multiplier, min_guarantors, guarantee_cover_percent)
SELECT 'Normal loan', 'For any purpose',
    (SELECT `value` FROM settings WHERE `key` = 'loan_interest_rate_monthly'),
    (SELECT `value` FROM settings WHERE `key` = 'loan_max_months'),
    (SELECT `value` FROM settings WHERE `key` = 'loan_savings_multiplier'),
    (SELECT `value` FROM settings WHERE `key` = 'loan_min_guarantors'),
    (SELECT `value` FROM settings WHERE `key` = 'loan_guarantee_cover_percent');

ALTER TABLE loans
    ADD COLUMN product_id INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN processing_fee DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER disbursement_ref,
    ADD COLUMN settled_early_on DATE NULL AFTER closed_on,
    ADD CONSTRAINT fk_loans_product FOREIGN KEY (product_id) REFERENCES loan_products (id);
UPDATE loans SET product_id = (SELECT MIN(id) FROM loan_products);

-- Extra amounts owed on a loan: late-payment penalties (and any other charge). Repayments clear
-- charges first, then instalments. `paid` is worked out from repayments, never typed in.
CREATE TABLE loan_charges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    type ENUM('penalty', 'fee') NOT NULL DEFAULT 'penalty',
    amount DECIMAL(15, 2) NOT NULL,
    paid DECIMAL(15, 2) NOT NULL DEFAULT 0,
    charged_on DATE NOT NULL,
    note VARCHAR(255) NULL,
    waived_at DATETIME NULL,
    waived_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_loan_charges_loan (loan_id),
    CONSTRAINT fk_loan_charges_loan FOREIGN KEY (loan_id) REFERENCES loans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How each repayment was split (rebuilt with the instalments), so the SACCO knows how much
-- interest and penalty it actually received in any period
CREATE TABLE loan_payment_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    loan_id INT UNSIGNED NOT NULL,
    part ENUM('principal', 'interest', 'penalty', 'fee') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    KEY idx_allocations_loan (loan_id),
    KEY idx_allocations_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The SACCO's own money besides loans: other income and its expenses (stationery, bank charges,
-- AGM costs...). Rows are only added; a mistake is a reversal row.
CREATE TABLE cashbook (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('income', 'expense') NOT NULL,
    category VARCHAR(60) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    entry_date DATE NOT NULL,
    description VARCHAR(255) NULL,
    reference VARCHAR(80) NULL,
    reversal_of INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cashbook_date (entry_date),
    UNIQUE KEY uq_cashbook_reversal (reversal_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A member leaving: what they were paid out, and how
CREATE TABLE member_exits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    savings_paid DECIMAL(15, 2) NOT NULL,
    shares_paid DECIMAL(15, 2) NOT NULL,
    cheque_no VARCHAR(80) NULL,
    exit_date DATE NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_member_exits_user (user_id),
    CONSTRAINT fk_member_exits_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notices from the office, shown on members' dashboards
CREATE TABLE notices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notices_dates (starts_on, ends_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Late-payment penalty: % of the overdue amount, charged when an admin runs penalties (0 = none)
INSERT INTO settings (`key`, `value`) VALUES ('loan_penalty_percent', '0');

-- Loan types with their own rules; top-ups and refinancing; replacing a guarantor
ALTER TABLE loan_products
    ADD COLUMN min_amount DECIMAL(15, 2) NULL AFTER savings_multiplier,
    ADD COLUMN max_amount DECIMAL(15, 2) NULL AFTER min_amount,
    -- Months of saving before this type of loan; NULL = the SACCO's general rule
    ADD COLUMN min_member_months SMALLINT UNSIGNED NULL AFTER max_amount,
    -- How many loans of this type a member can have at once
    ADD COLUMN max_open TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER min_member_months,
    -- Months of the year it can be applied for, e.g. "1,5,9" for school terms; NULL = any month
    ADD COLUMN open_months VARCHAR(40) NULL AFTER max_open,
    ADD COLUMN allow_top_up TINYINT(1) NOT NULL DEFAULT 0 AFTER open_months;

ALTER TABLE loans
    ADD COLUMN replaces_loan_id INT UNSIGNED NULL AFTER product_id,
    -- What the payout cleared on the loan it replaces
    ADD COLUMN settled_amount DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER processing_fee;

ALTER TABLE loan_guarantors
    MODIFY COLUMN status ENUM('pending', 'accepted', 'declined', 'withdrawn', 'released') NOT NULL DEFAULT 'pending',
    ADD COLUMN replaces_id INT UNSIGNED NULL AFTER requested_amount;

-- Bank and cash accounts: the account money actually went into or out of
ALTER TABLE gl_accounts ADD COLUMN is_money TINYINT(1) NOT NULL DEFAULT 0 AFTER type;
UPDATE gl_accounts SET is_money = 1 WHERE system_key IN ('bank', 'cash');
ALTER TABLE transactions ADD COLUMN money_account_id INT UNSIGNED NULL AFTER transfer_id;
ALTER TABLE loans ADD COLUMN money_account_id INT UNSIGNED NULL AFTER disbursement_ref;
ALTER TABLE loan_payments ADD COLUMN money_account_id INT UNSIGNED NULL AFTER transfer_id;
ALTER TABLE cashbook ADD COLUMN money_account_id INT UNSIGNED NULL AFTER account_id;
ALTER TABLE membership_fees ADD COLUMN money_account_id INT UNSIGNED NULL AFTER method;

-- Year-end closing: the year's income and expenses go to Retained surplus, and the year is locked
CREATE TABLE closed_years (
    year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    journal_id BIGINT UNSIGNED NULL,
    -- The surplus moved, and the totals at closing, to spot later changes
    surplus DECIMAL(15, 2) NOT NULL,
    snapshot TEXT NOT NULL,
    closed_by INT UNSIGNED NULL,
    closed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the SACCO expects to earn and spend, account by account, for a year
CREATE TABLE budgets (
    year SMALLINT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (year, account_id),
    CONSTRAINT fk_budgets_account FOREIGN KEY (account_id) REFERENCES gl_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

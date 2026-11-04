-- Double-entry books. Every money movement in the system (deposits, payroll, loans, repayments,
-- withdrawals and their fees, dividends, investments, income and expenses, transfers) is posted
-- as a balanced journal entry, so the trial balance, balance sheet and income statement always
-- agree with members' accounts. The office can add its own journal entries for anything else.

-- The chart of accounts. `system_key` marks the accounts the system posts to by itself.
CREATE TABLE gl_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(120) NOT NULL,
    type ENUM('asset', 'liability', 'equity', 'income', 'expense') NOT NULL,
    system_key VARCHAR(40) NULL,
    description VARCHAR(255) NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gl_accounts_code (code),
    UNIQUE KEY uq_gl_accounts_system (system_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO gl_accounts (code, name, type, system_key, description) VALUES
    ('1000', 'Bank account', 'asset', 'bank', 'Where members\' money, cheques and payroll remittances go in and out'),
    ('1010', 'Cash on hand', 'asset', 'cash', 'Petty cash kept at the office'),
    ('1100', 'Loans to members', 'asset', 'loans', 'Principal members still owe'),
    ('1110', 'Loan interest receivable', 'asset', 'interest_receivable', 'Interest that has fallen due and not yet been paid'),
    ('1120', 'Penalties receivable', 'asset', 'penalty_receivable', 'Late-payment penalties charged and not yet paid'),
    ('1200', 'Investments', 'asset', 'investments', 'Money the SACCO has invested, at cost'),
    ('2000', 'Members\' savings', 'liability', 'member_savings', 'What the SACCO owes members: their savings'),
    ('2100', 'Accounts payable', 'liability', 'payables', 'Bills the SACCO owes'),
    ('2900', 'Suspense', 'liability', 'suspense', 'Adjustments and overpayments waiting to be cleared with a journal entry'),
    ('3000', 'Share capital', 'equity', 'share_capital', 'Members\' shares'),
    ('3100', 'Retained surplus', 'equity', 'retained_surplus', 'Surpluses of past years kept in the SACCO'),
    ('3200', 'Dividends and profit paid to members', 'equity', 'distributions', 'Dividends on shares and investment profit shared out'),
    ('3900', 'Opening balances', 'equity', 'opening', 'Balances brought in from the records kept before this system'),
    ('4000', 'Loan interest', 'income', 'interest_income', 'Interest on members\' loans, as it falls due'),
    ('4010', 'Loan penalties', 'income', 'penalty_income', 'Late-payment penalties'),
    ('4020', 'Loan processing fees', 'income', 'loan_fee_income', 'Fees taken when a loan is paid out'),
    ('4030', 'Withdrawal fees', 'income', 'withdrawal_fee_income', 'Fees on savings withdrawals'),
    ('4100', 'Investment income', 'income', 'investment_income', 'Returns and gains on the SACCO\'s investments'),
    ('4200', 'Membership fees', 'income', NULL, NULL),
    ('4210', 'Bank interest', 'income', NULL, NULL),
    ('4220', 'Grants and donations', 'income', NULL, NULL),
    ('4900', 'Other income', 'income', 'other_income', NULL),
    ('5000', 'Stationery and printing', 'expense', NULL, NULL),
    ('5010', 'Bank charges', 'expense', NULL, NULL),
    ('5020', 'Meetings and AGM', 'expense', NULL, NULL),
    ('5030', 'Allowances', 'expense', NULL, NULL),
    ('5040', 'Audit fees', 'expense', NULL, NULL),
    ('5050', 'Transport', 'expense', NULL, NULL),
    ('5060', 'Communication', 'expense', NULL, NULL),
    ('5900', 'Other expenses', 'expense', 'other_expense', NULL);

-- Journal entries. `auto` ones are posted by the system from the records above and kept in step
-- with them; the others are entered by the office and only ever reversed, never changed.
CREATE TABLE journal_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_no VARCHAR(20) NULL,
    entry_date DATE NOT NULL,
    narration VARCHAR(255) NOT NULL,
    reference VARCHAR(80) NULL,
    auto TINYINT(1) NOT NULL DEFAULT 1,
    -- What it came from: deposit, payroll, loan_disbursement, loan_repayment, loan_interest...
    source VARCHAR(40) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    reversal_of BIGINT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_journal_date (entry_date),
    KEY idx_journal_source (source, source_id),
    UNIQUE KEY uq_journal_no (entry_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id BIGINT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    debit DECIMAL(15, 2) NOT NULL DEFAULT 0,
    credit DECIMAL(15, 2) NOT NULL DEFAULT 0,
    -- The member it concerns, when it does (for member ledgers)
    member_id INT UNSIGNED NULL,
    memo VARCHAR(255) NULL,
    KEY idx_journal_lines_entry (entry_id),
    KEY idx_journal_lines_account (account_id),
    CONSTRAINT fk_journal_lines_entry FOREIGN KEY (entry_id) REFERENCES journal_entries (id) ON DELETE CASCADE,
    CONSTRAINT fk_journal_lines_account FOREIGN KEY (account_id) REFERENCES gl_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Income and expenses now name their account in the chart
ALTER TABLE cashbook ADD COLUMN account_id INT UNSIGNED NULL AFTER category;
UPDATE cashbook c JOIN gl_accounts a ON a.name = c.category SET c.account_id = a.id;
UPDATE cashbook SET account_id = (SELECT id FROM gl_accounts WHERE system_key = 'other_income') WHERE account_id IS NULL AND kind = 'income';
UPDATE cashbook SET account_id = (SELECT id FROM gl_accounts WHERE system_key = 'other_expense') WHERE account_id IS NULL AND kind = 'expense';

-- Loans brought in from the old records came from the opening balances, not the bank
ALTER TABLE loans ADD COLUMN source ENUM('applied', 'old_records') NOT NULL DEFAULT 'applied' AFTER product_id;
UPDATE loans SET source = 'old_records' WHERE decision_note = 'Brought in from the old records';

-- Moving money between accounts: savings to shares, to another member, or to a loan
CREATE TABLE transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_no VARCHAR(20) NULL,
    from_user_id INT UNSIGNED NOT NULL,
    from_account ENUM('savings', 'shares') NOT NULL,
    to_user_id INT UNSIGNED NOT NULL,
    to_account ENUM('savings', 'shares', 'loan') NOT NULL,
    loan_id INT UNSIGNED NULL,
    amount DECIMAL(15, 2) NOT NULL,
    value_date DATE NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status ENUM('posted', 'reversed') NOT NULL DEFAULT 'posted',
    out_transaction_id BIGINT UNSIGNED NULL,
    in_transaction_id BIGINT UNSIGNED NULL,
    loan_payment_id BIGINT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_by INT UNSIGNED NULL,
    reversed_on DATE NULL,
    reversal_reason VARCHAR(255) NULL,
    UNIQUE KEY uq_transfers_no (transfer_no),
    KEY idx_transfers_from (from_user_id),
    KEY idx_transfers_to (to_user_id),
    CONSTRAINT fk_transfers_from FOREIGN KEY (from_user_id) REFERENCES users (id),
    CONSTRAINT fk_transfers_to FOREIGN KEY (to_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions MODIFY COLUMN type ENUM('opening_balance', 'deposit', 'withdrawal', 'adjustment', 'dividend', 'reversal', 'charge', 'profit', 'transfer') NOT NULL;
ALTER TABLE transactions ADD COLUMN transfer_id INT UNSIGNED NULL AFTER dividend_run_id;
ALTER TABLE loan_payments ADD COLUMN transfer_id INT UNSIGNED NULL AFTER batch_id;

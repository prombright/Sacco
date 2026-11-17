-- The committee's decisions of November 2026 (see each line)

-- Withdrawals: the cheque is the full amount asked for; the fee comes off the remaining savings
UPDATE settings SET value = 'on_top' WHERE `key` = 'withdrawal_fee_mode';
INSERT IGNORE INTO settings (`key`, value) VALUES ('withdrawal_fee_mode', 'on_top');

-- Loan processing fee: taken from the borrower's savings at payout, so the cheque is the full loan.
-- Each loan remembers how its fee was taken (loans paid out before this keep theirs).
ALTER TABLE loans ADD COLUMN fee_from_savings TINYINT(1) NOT NULL DEFAULT 0 AFTER processing_fee;
ALTER TABLE transactions MODIFY COLUMN type ENUM('opening_balance', 'deposit', 'withdrawal', 'adjustment', 'dividend', 'reversal',
    'charge', 'profit', 'transfer', 'membership_fee', 'subscription', 'loan_fee') NOT NULL;
INSERT INTO settings (`key`, value) VALUES ('loan_fee_from', 'savings') ON DUPLICATE KEY UPDATE value = 'savings';

-- Guarantors: only the part of a loan above the member's own savings needs guaranteeing, by one
-- guarantor or more. self_cover is the part the member's savings covered when they applied; it is
-- held from their savings while the loan runs (less as it is repaid).
ALTER TABLE loans ADD COLUMN self_cover DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER amount;
UPDATE loan_products SET min_guarantors = 1 WHERE min_guarantors > 1;
UPDATE settings SET value = '1' WHERE `key` = 'loan_min_guarantors' AND value > '1';

-- Loans open: a member may hold several loans at once (the borrowing limit still counts what they owe)
UPDATE loan_products SET max_open = 0;

-- An entry made by mistake (e.g. on the wrong member) and its correction are marked, so the member
-- never sees either; the office and the books keep both, with who did it and why
ALTER TABLE transactions ADD COLUMN in_error TINYINT(1) NOT NULL DEFAULT 0 AFTER reversal_of;

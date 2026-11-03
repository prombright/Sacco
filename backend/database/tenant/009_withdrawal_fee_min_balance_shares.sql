-- Committee rules (Nov 2026): a 3% fee on savings withdrawals, at least 20,000 left in savings,
-- and every member holding at least 5 shares of 40,000.

-- The fee on each withdrawal, and whether it was taken out of the amount (cheque is smaller) or
-- charged on top (savings go down by amount + fee)
ALTER TABLE withdrawal_requests
    ADD COLUMN fee DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER amount,
    ADD COLUMN fee_on_top TINYINT(1) NOT NULL DEFAULT 0 AFTER fee;

-- A charge taken from savings (the withdrawal fee when it is charged on top)
ALTER TABLE transactions MODIFY COLUMN type ENUM('opening_balance', 'deposit', 'withdrawal', 'adjustment', 'dividend', 'reversal', 'charge') NOT NULL;

INSERT INTO settings (`key`, `value`) VALUES
    ('withdrawal_fee_percent', '3'),
    -- from_amount: the fee comes out of the withdrawal; on_top: it is charged as well
    ('withdrawal_fee_mode', 'from_amount'),
    ('savings_min_balance', '20000'),
    ('min_shares', '5');

-- The share price, unless the SACCO already set one
UPDATE settings SET `value` = '40000' WHERE `key` = 'share_price' AND `value` IN ('0', '');

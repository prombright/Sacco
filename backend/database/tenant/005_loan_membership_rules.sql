-- Committee's loan rules (Nov 2026): borrowers must have saved for 6 months, guarantors must have
-- been members for 6 months, loans up to 2x savings, and a guarantor may agree to a different
-- amount than the borrower asked for.

-- When they joined the SACCO. Blank means "from their first savings entry"; admins set it for
-- members who joined before this system.
ALTER TABLE users ADD COLUMN joined_on DATE NULL AFTER account_no;

-- What the borrower asked for; `amount` is what the guarantor actually agreed to
ALTER TABLE loan_guarantors ADD COLUMN requested_amount DECIMAL(15, 2) NULL AFTER amount;
UPDATE loan_guarantors SET requested_amount = amount;

INSERT INTO settings (`key`, `value`) VALUES
    ('loan_min_member_months', '6'),
    ('guarantor_min_member_months', '6');

-- Only if nobody has changed it from the old default
UPDATE settings SET `value` = '2' WHERE `key` = 'loan_savings_multiplier' AND `value` = '3';

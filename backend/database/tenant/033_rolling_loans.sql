-- Loans worked out month by month from November 2026: interest on what is owed at the start of the
-- month; interest not paid is added to the principal; what is owed is spread again over the months
-- left (so the next payment changes); paying more cuts the principal. Each month keeps what was
-- demanded, what was paid, interest added to the principal and how much it fell short.
ALTER TABLE loan_installments
  ADD COLUMN original_principal DECIMAL(15,2) NULL AFTER interest,
  ADD COLUMN original_interest DECIMAL(15,2) NULL AFTER original_principal,
  ADD COLUMN demanded DECIMAL(15,2) NULL AFTER interest_paid,
  ADD COLUMN paid_in_month DECIMAL(15,2) NULL AFTER demanded,
  ADD COLUMN capitalised DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER paid_in_month,
  ADD COLUMN short DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER capitalised,
  ADD COLUMN balance_after DECIMAL(15,2) NULL AFTER short;

-- The schedule as it was made, for the months before the new way starts
UPDATE loan_installments SET original_principal = principal, original_interest = interest;

INSERT INTO settings (`key`, `value`) VALUES ('loan_rolling_from', '2026-11-01')
  ON DUPLICATE KEY UPDATE `value` = `value`;

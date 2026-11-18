-- The loan processing fee is taken from the borrower's savings when the loan is approved (not at
-- payout): when, and the savings entry, so a loan rejected or cancelled before payout gives it back
ALTER TABLE loans
  ADD COLUMN fee_charged_on DATE NULL AFTER fee_from_savings,
  ADD COLUMN fee_transaction_id BIGINT UNSIGNED NULL AFTER fee_charged_on;

-- Loans paid out before: the fee was taken on the payout day
UPDATE loans SET fee_charged_on = disbursed_on WHERE processing_fee > 0 AND disbursed_on IS NOT NULL;

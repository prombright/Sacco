-- A slow payer is marked by the office, not worked out by the system (repayments come off salaries,
-- so a late one is often not the member's doing). Only a marked member's borrowing limit is cut.
ALTER TABLE users
    ADD COLUMN slow_payer_since DATE NULL AFTER credit_grade,
    ADD COLUMN slow_payer_note VARCHAR(255) NULL AFTER slow_payer_since,
    ADD COLUMN slow_payer_by INT UNSIGNED NULL AFTER slow_payer_note;
-- Nobody is a slow payer until the office says so
UPDATE users SET credit_grade = NULL;

-- The committee's fees: a membership fee paid once, a subscription every year, and a flat
-- processing fee on every loan.

ALTER TABLE membership_fees
    ADD COLUMN kind ENUM('subscription', 'membership') NOT NULL DEFAULT 'subscription' AFTER user_id,
    MODIFY COLUMN method ENUM('savings', 'cash', 'old_system') NULL,
    DROP INDEX uq_membership_fee,
    ADD UNIQUE KEY uq_membership_fee (user_id, kind, year);

ALTER TABLE transactions MODIFY COLUMN type ENUM('opening_balance', 'deposit', 'withdrawal', 'adjustment', 'dividend', 'reversal',
    'charge', 'profit', 'transfer', 'membership_fee', 'subscription') NOT NULL;

UPDATE gl_accounts SET description = 'The membership fee, paid once' WHERE system_key = 'membership_fee_income';
INSERT IGNORE INTO gl_accounts (code, name, type, system_key, description) VALUES
    ('4205', 'Annual subscriptions', 'income', 'subscription_income', 'The subscription members pay every year');

INSERT INTO settings (`key`, `value`) VALUES ('membership_fee_once', '25000'), ('subscription_fee_annual', '20000');
DELETE FROM settings WHERE `key` = 'membership_fee_annual';
UPDATE committee_questions
    SET setting_key = 'membership_fee_once', question = 'Is the membership fee UGX 25,000 paid once, with a subscription of UGX 20,000 every year?'
    WHERE setting_key = 'membership_fee_annual';

ALTER TABLE loan_products
    ADD COLUMN processing_fee DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER savings_multiplier,
    DROP COLUMN processing_fee_percent;
UPDATE loan_products SET processing_fee = 10000;

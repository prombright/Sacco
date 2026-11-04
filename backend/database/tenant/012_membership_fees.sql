-- The yearly membership fee. Each year the office charges every active member; the fee is then
-- taken from savings, paid in cash, or waived. Income goes to "Membership fees" in the books.
CREATE TABLE membership_fees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    status ENUM('unpaid', 'paid', 'waived') NOT NULL DEFAULT 'unpaid',
    method ENUM('savings', 'cash') NULL,
    paid_on DATE NULL,
    reference VARCHAR(80) NULL,
    transaction_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    paid_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_membership_fee (user_id, year),
    KEY idx_membership_fee_year (year, status),
    CONSTRAINT fk_membership_fee_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Set on the Rules page; 0 until the committee sets it
INSERT INTO settings (`key`, `value`) VALUES ('membership_fee_annual', '0');

UPDATE gl_accounts SET system_key = 'membership_fee_income', description = 'The yearly membership fee' WHERE code = '4200';

ALTER TABLE transactions MODIFY COLUMN type ENUM('opening_balance', 'deposit', 'withdrawal', 'adjustment', 'dividend', 'reversal', 'charge', 'profit', 'transfer', 'membership_fee') NOT NULL;

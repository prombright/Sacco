-- Interest members' savings earn: a rate the super admin sets (a year, or a month). At the end of
-- each month every member earns it on their savings that day; it is held for them (owed by the
-- SACCO), apart from their savings: it doesn't count for loans or withdrawals.
CREATE TABLE IF NOT EXISTS savings_interest (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  month CHAR(7) NOT NULL,
  balance DECIMAL(15,2) NOT NULL,
  rate_percent_month DECIMAL(9,5) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_member_month (user_id, month),
  KEY idx_month (month)
);

INSERT INTO settings (`key`, `value`) VALUES ('savings_interest_rate', '0'), ('savings_interest_per', 'year')
  ON DUPLICATE KEY UPDATE `value` = `value`;

-- What it costs the SACCO, in the books
INSERT INTO gl_accounts (code, name, type, system_key)
SELECT '5070', 'Interest on members\' savings', 'expense', 'savings_interest_expense'
WHERE NOT EXISTS (SELECT 1 FROM gl_accounts WHERE system_key = 'savings_interest_expense');

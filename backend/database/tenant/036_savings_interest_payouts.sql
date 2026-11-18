-- Paying out the savings interest held for members: in cash (it leaves the SACCO's money) or into
-- their savings (it becomes savings at once). Either way it is no longer held for them.
CREATE TABLE IF NOT EXISTS savings_interest_payouts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  method ENUM('cash', 'savings') NOT NULL,
  paid_on DATE NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,
  money_account_id INT UNSIGNED NULL,
  reference VARCHAR(80) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_member (user_id)
);

ALTER TABLE transactions MODIFY type ENUM('opening_balance','deposit','withdrawal','adjustment','dividend','reversal','charge','profit','transfer','membership_fee','subscription','loan_fee','interest') NOT NULL;

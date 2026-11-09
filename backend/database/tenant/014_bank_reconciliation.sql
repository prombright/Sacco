-- Bank reconciliation: agreeing a bank (or cash) account in the books with its statement, by
-- ticking the entries that have reached the statement.
CREATE TABLE bank_reconciliations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    statement_date DATE NOT NULL,
    statement_balance DECIMAL(15, 2) NOT NULL,
    status ENUM('open', 'completed') NOT NULL DEFAULT 'open',
    created_by INT UNSIGNED NULL,
    completed_by INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bank_rec_account (account_id, status, statement_date),
    CONSTRAINT fk_bank_rec_account FOREIGN KEY (account_id) REFERENCES gl_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The entries ticked. System entries are rebuilt from the records with new ids, so an entry is
-- identified by what it came from (`line_key`), which stays the same.
CREATE TABLE bank_rec_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reconciliation_id INT UNSIGNED NOT NULL,
    line_key VARCHAR(120) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    UNIQUE KEY uq_bank_rec_item (reconciliation_id, line_key),
    KEY idx_bank_rec_key (line_key),
    CONSTRAINT fk_bank_rec_item FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

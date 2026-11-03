-- Investments and sharing their profit with members; dividends at the SACCO's yearly rate.
--
-- The SACCO invests (a unit trust, fixed deposit, treasury bill, land to resell...). Each
-- investment has an expected yearly return; the profit it actually brings (interest received, or
-- the gain when it is sold) is recorded, and at year end the year's profit is shared among
-- members in proportion to their savings.

CREATE TABLE investments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    kind ENUM('mutual_fund', 'fixed_deposit', 'treasury', 'shares', 'property', 'business', 'other') NOT NULL DEFAULT 'mutual_fund',
    -- Where it is held: the fund manager, bank...
    institution VARCHAR(120) NULL,
    amount DECIMAL(15, 2) NOT NULL,
    started_on DATE NOT NULL,
    -- Expected profit a year, % of the amount (e.g. 12)
    expected_return_percent DECIMAL(6, 3) NOT NULL DEFAULT 0,
    status ENUM('active', 'closed') NOT NULL DEFAULT 'active',
    closed_on DATE NULL,
    -- What it was sold or cashed in for
    closing_value DECIMAL(15, 2) NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profit an investment actually brought: income (interest, distributions) or the gain/loss when
-- sold. Rows are only added; a mistake is a reversal row (equal and opposite).
CREATE TABLE investment_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    investment_id INT UNSIGNED NOT NULL,
    kind ENUM('income', 'gain') NOT NULL DEFAULT 'income',
    -- Negative for a loss on sale, or for a reversal
    amount DECIMAL(15, 2) NOT NULL,
    received_on DATE NOT NULL,
    note VARCHAR(255) NULL,
    reversal_of INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_investment_returns_date (received_on),
    UNIQUE KEY uq_investment_returns_reversal (reversal_of),
    CONSTRAINT fk_investment_returns_investment FOREIGN KEY (investment_id) REFERENCES investments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payout runs now cover both dividends (on shares) and profit shares (on savings)
ALTER TABLE dividend_runs
    ADD COLUMN kind ENUM('dividend', 'profit') NOT NULL DEFAULT 'dividend' AFTER id,
    ADD COLUMN year SMALLINT UNSIGNED NULL AFTER label,
    ADD COLUMN pool DECIMAL(15, 2) NULL AFTER rate_percent,
    ADD COLUMN basis VARCHAR(40) NULL AFTER pool,
    ADD COLUMN credit_account ENUM('savings', 'shares') NOT NULL DEFAULT 'shares' AFTER basis;

-- A member's share of the SACCO's investment profit
ALTER TABLE transactions MODIFY COLUMN type ENUM('opening_balance', 'deposit', 'withdrawal', 'adjustment', 'dividend', 'reversal', 'charge', 'profit') NOT NULL;

INSERT INTO settings (`key`, `value`) VALUES
    -- Dividend a year, % of each member's share capital
    ('dividend_rate_percent', '40'),
    -- Profit is shared by average_savings (month-end balances over the year) or year_end_savings
    ('profit_basis', 'average_savings'),
    -- Where members' profit share is credited: savings or shares
    ('profit_credit_account', 'savings');

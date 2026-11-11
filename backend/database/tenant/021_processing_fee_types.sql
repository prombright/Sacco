-- How a loan type charges its processing fee: a flat amount, a percentage of the loan, or a fee
-- for each band of loan size (e.g. 100,000 to 1,000,000 pays 10,000).
ALTER TABLE loan_products
    ADD COLUMN processing_fee_type ENUM('flat', 'percent', 'bands') NOT NULL DEFAULT 'flat' AFTER savings_multiplier,
    ADD COLUMN processing_fee_percent DECIMAL(5, 2) NOT NULL DEFAULT 0 AFTER processing_fee,
    -- [{"from": "100000.00", "to": "1000000.00", "fee": "10000.00"}, ...]; "to" null = no upper limit
    ADD COLUMN processing_fee_bands TEXT NULL AFTER processing_fee_percent;

-- A sentence about each SACCO for its public homepage
ALTER TABLE saccos ADD COLUMN about VARCHAR(255) NULL AFTER slogan;
UPDATE saccos SET about = 'The savings and credit cooperative of St. Mark''s staff: save a little every month, borrow at fair rates, and share in what we earn together.' WHERE code = 'smacon';

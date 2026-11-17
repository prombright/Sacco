-- SMACON SACCO's logo (the three pages), shipped in frontend/public/brand; a logo uploaded on
-- Setup is kept
UPDATE saccos SET logo_url = 'brand/smacon-mark.png' WHERE code = 'smacon' AND (logo_url IS NULL OR logo_url = '');

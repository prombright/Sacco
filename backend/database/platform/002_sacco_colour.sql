-- The SACCO product's own colour (deep navy), so it never looks like Tusome's green.
-- Only SACCOs still on the old default change; one that chose its own colour keeps it.
ALTER TABLE saccos ALTER COLUMN brand_color SET DEFAULT '#1b3a6b';
UPDATE saccos SET brand_color = '#1b3a6b' WHERE brand_color = '#15803d';

-- Each SACCO's own look on the login page: a photo and its slogan. SMACON's come from its banner
-- ("...Together for a Better Tomorrow") and its royal blue.
ALTER TABLE saccos
    ADD COLUMN login_image VARCHAR(255) NULL AFTER logo_url,
    ADD COLUMN slogan VARCHAR(160) NULL AFTER login_image,
    ALTER COLUMN brand_color SET DEFAULT '#2b35af';

UPDATE saccos SET brand_color = '#2b35af' WHERE brand_color IN ('#1b3a6b', '#15803d');
UPDATE saccos SET login_image = 'brand/smacon-login.jpg', slogan = 'Together for a better tomorrow' WHERE code = 'smacon';

-- Office roles: super admin (heads everything), admin (approves loans, does what Setup allows),
-- staff (sees everything, approves and changes nothing), member
ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'staff', 'member') NOT NULL DEFAULT 'member';

-- Admins who could do nothing (no admin rights switched on in Setup) were view-only: they are staff now
UPDATE users SET role = 'staff'
WHERE role = 'admin'
  AND NOT EXISTS (SELECT 1 FROM settings WHERE `key` LIKE 'admins\_can\_%' AND `value` = 'yes');

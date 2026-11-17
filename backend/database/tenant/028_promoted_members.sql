-- Members whose own account was changed to Admin / Super admin (before office access was added):
-- they hold savings, loans, guarantees or fees, which office accounts never do. Each goes back to
-- being a member, and gets linked office access with the same role: they log in as themselves and
-- use the round switch to reach the office.
CREATE TEMPORARY TABLE promoted_members AS
SELECT u.id, u.full_name, u.role
FROM users u
WHERE u.role IN ('admin', 'super_admin')
  AND (EXISTS (SELECT 1 FROM transactions t WHERE t.user_id = u.id)
    OR EXISTS (SELECT 1 FROM loans l WHERE l.user_id = u.id)
    OR EXISTS (SELECT 1 FROM loan_guarantors g WHERE g.guarantor_id = u.id)
    OR EXISTS (SELECT 1 FROM membership_fees f WHERE f.user_id = u.id))
  AND NOT EXISTS (SELECT 1 FROM users o WHERE o.linked_member_id = u.id);

-- Their office access: no login of its own ('!' never matches a password)
INSERT INTO users (full_name, role, status, source, password_hash, must_change_password, linked_member_id, office_via_member)
SELECT p.full_name, p.role, 'active', 'manual', '!', 0, p.id, 1 FROM promoted_members p;

UPDATE users SET account_no = CONCAT('M', LPAD(id, 5, '0')) WHERE office_via_member = 1 AND account_no IS NULL;

-- Their own account is a member account again (an office link it had is dropped: they now reach the office through their member login)
UPDATE users u JOIN promoted_members p ON p.id = u.id SET u.role = 'member', u.linked_member_id = NULL;

DROP TEMPORARY TABLE promoted_members;

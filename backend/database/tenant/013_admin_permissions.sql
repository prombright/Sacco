-- The super admin runs the SACCO. Admins can see everything and download every report, and are
-- told about major changes; what else they may do is switched on by the super admin, area by area.
INSERT INTO settings (`key`, `value`) VALUES
    ('admins_can_members', 'no'),
    ('admins_can_money', 'no'),
    ('admins_can_loans', 'no'),
    ('admins_can_notices', 'no');

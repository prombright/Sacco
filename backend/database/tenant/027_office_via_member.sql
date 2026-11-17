-- Office access given to a member (their role set to Admin or Super admin): a linked office account
-- with no login of its own; the person logs in as themselves and uses the round switch
ALTER TABLE users ADD COLUMN office_via_member TINYINT(1) NOT NULL DEFAULT 0 AFTER linked_member_id;

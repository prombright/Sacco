-- Office staff who also save: their office account is linked to their own member account, so they
-- can switch between the two without logging out (set by the super admin)
ALTER TABLE users
    ADD COLUMN linked_member_id INT UNSIGNED NULL AFTER role,
    ADD UNIQUE KEY uq_users_linked_member (linked_member_id);

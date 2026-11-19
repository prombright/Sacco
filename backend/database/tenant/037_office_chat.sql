-- Members write to the SACCO office; super admins read and answer. One conversation per member.
CREATE TABLE IF NOT EXISTS office_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  from_office TINYINT(1) NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_member (member_id, id),
  KEY idx_unread (from_office, read_at)
);

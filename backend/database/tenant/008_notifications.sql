-- Messages to a member from the SACCO: loan approved (pick up your cheque), rejected, paid out,
-- asked to guarantee, a guarantor's answer, withdrawal ready... Shown under the bell in the app.
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    -- Where tapping it goes in the app, e.g. /loans/12
    link VARCHAR(160) NULL,
    -- loan_approved, guarantee_request... (for an icon and colour)
    kind VARCHAR(40) NOT NULL DEFAULT 'info',
    created_by INT UNSIGNED NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (user_id, read_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

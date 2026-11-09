-- Failed sign-ins per internet address, across all SACCOs and the developer portal
CREATE TABLE login_throttle (
    ip VARCHAR(45) NOT NULL PRIMARY KEY,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

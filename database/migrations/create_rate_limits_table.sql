CREATE TABLE rate_limits (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                scope_hash CHAR(64) NOT NULL,
                attempted_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                INDEX idx_auth_rate_limits_scope_time (scope_hash, attempted_at),
                INDEX idx_auth_rate_limits_attempted_at (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
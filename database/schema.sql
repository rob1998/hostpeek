CREATE TABLE IF NOT EXISTS previews (
    label VARCHAR(63) NOT NULL PRIMARY KEY,
    hostname VARCHAR(253) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    original_url TEXT NOT NULL DEFAULT '',
    upstream_scheme VARCHAR(5) NULL,
    upstream_port INT UNSIGNED NULL,
    KEY previews_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE previews ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER expires_at;
ALTER TABLE previews ADD COLUMN IF NOT EXISTS original_url TEXT NOT NULL DEFAULT '';
ALTER TABLE previews ADD COLUMN IF NOT EXISTS upstream_scheme VARCHAR(5) NULL;
ALTER TABLE previews ADD COLUMN IF NOT EXISTS upstream_port INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS login_attempts (
    scope VARCHAR(80) NOT NULL,
    remote_ip VARCHAR(45) NOT NULL,
    window_started BIGINT UNSIGNED NOT NULL,
    attempt_count INT UNSIGNED NOT NULL,
    PRIMARY KEY (scope, remote_ip),
    KEY login_attempts_expiry (window_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

DROP TABLE IF EXISTS preview_leases;
DROP TABLE IF EXISTS preview_admission;
DROP TABLE IF EXISTS rate_limits;

-- YOLOTASK V2 Phase 2: accounts and security, migration 0002.
-- Additive only: new columns on users/user_sessions plus verification,
-- throttle and staff-invite support. Safe to run once after 0001.

ALTER TABLE users
    ADD COLUMN phone VARCHAR(20) NULL AFTER email,
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash,
    ADD COLUMN closed_at DATETIME NULL AFTER last_login_at,
    ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER closed_at,
    ADD KEY ix_users_email_verified (email_verified_at);

ALTER TABLE user_sessions
    ADD COLUMN device_label VARCHAR(120) NULL AFTER user_agent_hash,
    ADD COLUMN last_seen_at DATETIME NULL AFTER device_label;

ALTER TABLE password_reset_tokens
    ADD COLUMN purpose ENUM('reset', 'invite') NOT NULL DEFAULT 'reset' AFTER token_hash;

CREATE TABLE email_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verifications_token (token_hash),
    KEY ix_email_verifications_user (user_id, used_at, expires_at),
    CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_rate_limits_lookup (bucket, key_hash, created_at),
    KEY ix_rate_limits_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

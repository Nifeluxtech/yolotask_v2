-- YOLOTASK V2 Phase 4: task reservations, submissions and private proof
-- files, migration 0006. Additive only; safe to run once after 0005.
-- One reservation row and one submission row per earner per campaign
-- (a resubmission updates the same submission). Proof files live in the
-- private runtime directory; only their relative path and hash are stored.
-- submission_appeals is created for the Phase 5 appeal flow and is not used yet.
-- The last section adds Phase 3 hardening (encrypted bank account numbers).

CREATE TABLE task_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    earner_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active', 'submitted', 'expired', 'released') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    started_count INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_task_reservations_campaign_earner (campaign_id, earner_user_id),
    KEY ix_task_reservations_status_expiry (status, expires_at),
    KEY ix_task_reservations_earner (earner_user_id, status),
    CONSTRAINT fk_task_reservations_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
    CONSTRAINT fk_task_reservations_earner FOREIGN KEY (earner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    earner_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    comment VARCHAR(500) NULL,
    instructions_version INT UNSIGNED NOT NULL,
    unit_price_kobo BIGINT NOT NULL,
    unit_reward_kobo BIGINT NOT NULL,
    reject_reason VARCHAR(255) NULL,
    decision_source ENUM('advertiser', 'auto', 'admin') NULL,
    decided_by_user_id BIGINT UNSIGNED NULL,
    submitted_at DATETIME NOT NULL,
    auto_approve_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    reward_status ENUM('none', 'held', 'released') NOT NULL DEFAULT 'none',
    reward_release_at DATETIME NULL,
    reward_released_at DATETIME NULL,
    approve_transaction_id BIGINT UNSIGNED NULL,
    release_transaction_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_submissions_campaign_earner (campaign_id, earner_user_id),
    KEY ix_submissions_campaign_status (campaign_id, status, id),
    KEY ix_submissions_earner (earner_user_id, id),
    KEY ix_submissions_auto (status, auto_approve_at),
    KEY ix_submissions_reward (reward_status, reward_release_at),
    CONSTRAINT fk_submissions_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
    CONSTRAINT fk_submissions_earner FOREIGN KEY (earner_user_id) REFERENCES users(id),
    CONSTRAINT fk_submissions_decider FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_submissions_approve_tx FOREIGN KEY (approve_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_submissions_release_tx FOREIGN KEY (release_transaction_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submission_proofs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    storage_path VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    upload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stored_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_submission_proofs_path (storage_path),
    KEY ix_submission_proofs_submission (submission_id, attempt),
    KEY ix_submission_proofs_upload_hash (upload_sha256),
    CONSTRAINT fk_submission_proofs_submission FOREIGN KEY (submission_id) REFERENCES submissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submission_appeals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    earner_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open', 'upheld', 'overturned', 'withdrawn') NOT NULL DEFAULT 'open',
    message VARCHAR(1000) NOT NULL,
    resolution_note VARCHAR(255) NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_submission_appeals_status (status, id),
    KEY ix_submission_appeals_submission (submission_id),
    CONSTRAINT fk_submission_appeals_submission FOREIGN KEY (submission_id) REFERENCES submissions(id),
    CONSTRAINT fk_submission_appeals_earner FOREIGN KEY (earner_user_id) REFERENCES users(id),
    CONSTRAINT fk_submission_appeals_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 3 hardening (added with Phase 4): bank account numbers are now
-- encrypted at rest by the application (Security\Crypto::seal: "enc2:"
-- AES-256-GCM, or legacy "enc1:" libsodium, + base64), so the columns grow;
-- account_last4 keeps the masked display without decrypting. Existing plaintext rows keep working and are sealed by
-- scripts/encrypt-bank-details.php. bank_accounts.changed_at records when an
-- existing account was replaced (withdrawal cooldown after a change).
ALTER TABLE bank_accounts
    MODIFY account_number VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD COLUMN account_last4 CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER account_number,
    ADD COLUMN changed_at DATETIME NULL AFTER name_verified_via;

UPDATE bank_accounts SET account_last4 = RIGHT(account_number, 4) WHERE account_last4 IS NULL AND account_number NOT LIKE 'enc1:%' AND account_number NOT LIKE 'enc2:%';

ALTER TABLE withdrawals
    MODIFY account_number VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD COLUMN account_last4 CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER account_number;

UPDATE withdrawals SET account_last4 = RIGHT(account_number, 4) WHERE account_last4 IS NULL AND account_number NOT LIKE 'enc1:%' AND account_number NOT LIKE 'enc2:%';

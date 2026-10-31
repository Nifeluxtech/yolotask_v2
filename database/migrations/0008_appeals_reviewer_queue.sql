-- YOLOTASK V2 Phase 5: earner appeals, reviewer queues (appeals + stale
-- submissions), review claims, reviewer action log, advertiser debts and the
-- advertiser quality flag settings, migration 0008 (appeals_reviewer_queue).
-- Additive only; safe to run once after 0007. (0008_notifications_* beside it
-- is still a placeholder and is skipped by scripts/migrate.php.)
-- Plain DDL/DML only: no triggers, procedures, events or DEFINER clauses, so it
-- runs as the ordinary cPanel database user.
-- submission_appeals was created (empty, unused) by 0006; it gains the columns
-- the appeal flow needs and one appeal per submission becomes a unique key.

ALTER TABLE submission_appeals
    MODIFY resolution_note VARCHAR(500) NULL,
    ADD COLUMN campaign_id BIGINT UNSIGNED NULL AFTER submission_id,
    ADD COLUMN advertiser_user_id BIGINT UNSIGNED NULL AFTER earner_user_id,
    ADD COLUMN proof_id BIGINT UNSIGNED NULL AFTER message,
    ADD COLUMN instructions_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER proof_id,
    ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER instructions_version,
    ADD COLUMN rejection_source ENUM('advertiser', 'auto', 'admin', 'reviewer', 'appeal') NULL AFTER rejection_reason,
    ADD COLUMN rejected_by_user_id BIGINT UNSIGNED NULL AFTER rejection_source,
    ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by_user_id,
    ADD COLUMN decided_by_role ENUM('reviewer', 'admin') NULL AFTER resolved_by_user_id,
    ADD COLUMN escrow_kobo BIGINT NOT NULL DEFAULT 0 AFTER resolved_at,
    ADD COLUMN wallet_kobo BIGINT NOT NULL DEFAULT 0 AFTER escrow_kobo,
    ADD COLUMN platform_kobo BIGINT NOT NULL DEFAULT 0 AFTER wallet_kobo,
    ADD COLUMN over_budget TINYINT(1) NOT NULL DEFAULT 0 AFTER platform_kobo,
    ADD COLUMN funding_transaction_id BIGINT UNSIGNED NULL AFTER over_budget,
    ADD COLUMN approve_transaction_id BIGINT UNSIGNED NULL AFTER funding_transaction_id,
    ADD COLUMN reverse_transaction_id BIGINT UNSIGNED NULL AFTER approve_transaction_id,
    ADD COLUMN admin_override TINYINT(1) NOT NULL DEFAULT 0 AFTER reverse_transaction_id,
    ADD COLUMN override_from_status ENUM('open', 'upheld', 'overturned') NULL AFTER admin_override,
    ADD COLUMN overridden_by_user_id BIGINT UNSIGNED NULL AFTER override_from_status,
    ADD COLUMN overridden_at DATETIME NULL AFTER overridden_by_user_id,
    ADD COLUMN override_note VARCHAR(500) NULL AFTER overridden_at,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_submission_appeals_submission (submission_id),
    ADD KEY ix_submission_appeals_queue (status, created_at, id),
    ADD KEY ix_submission_appeals_advertiser (advertiser_user_id, status, resolved_at),
    ADD KEY ix_submission_appeals_campaign (campaign_id, id),
    ADD CONSTRAINT fk_submission_appeals_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
    ADD CONSTRAINT fk_submission_appeals_advertiser FOREIGN KEY (advertiser_user_id) REFERENCES users(id),
    ADD CONSTRAINT fk_submission_appeals_proof FOREIGN KEY (proof_id) REFERENCES submission_proofs(id),
    ADD CONSTRAINT fk_submission_appeals_rejecter FOREIGN KEY (rejected_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_submission_appeals_overrider FOREIGN KEY (overridden_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_submission_appeals_fund_tx FOREIGN KEY (funding_transaction_id) REFERENCES ledger_transactions(id),
    ADD CONSTRAINT fk_submission_appeals_approve_tx FOREIGN KEY (approve_transaction_id) REFERENCES ledger_transactions(id),
    ADD CONSTRAINT fk_submission_appeals_reverse_tx FOREIGN KEY (reverse_transaction_id) REFERENCES ledger_transactions(id);

-- Who decided a submission: reviewers (stale queue) and appeals join the
-- Phase 4 sources.
ALTER TABLE submissions
    MODIFY decision_source ENUM('advertiser', 'auto', 'admin', 'reviewer', 'appeal') NULL;

-- An optional extra screenshot attached to an appeal is stored like any proof.
ALTER TABLE submission_proofs
    ADD COLUMN is_appeal TINYINT(1) NOT NULL DEFAULT 0 AFTER attempt;

-- Approvals paid on appeal after the campaign's slots were already used
-- (funded from the advertiser wallet or the platform, never silently):
-- they do not take a slot, so capacity = quantity + extra_approved_count.
ALTER TABLE campaigns
    ADD COLUMN extra_approved_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rejected_count;

ALTER TABLE campaign_funding_events
    MODIFY event ENUM('lock', 'refund', 'appeal_topup') NOT NULL;

-- One reviewer at a time works an item (appeal or stale submission); a claim
-- expires after reviews.claim_minutes.
CREATE TABLE review_claims (
    item_type ENUM('appeal', 'submission') NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    claimed_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (item_type, item_id),
    KEY ix_review_claims_reviewer (reviewer_user_id, expires_at),
    KEY ix_review_claims_expiry (expires_at),
    CONSTRAINT fk_review_claims_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every reviewer/admin decision in the queues, kept for reviewer pay later.
CREATE TABLE reviewer_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    reviewer_role ENUM('reviewer', 'admin') NOT NULL,
    action ENUM('appeal_upheld', 'appeal_overturned', 'appeal_override_upheld', 'appeal_override_overturned', 'submission_approved', 'submission_rejected') NOT NULL,
    item_type ENUM('appeal', 'submission') NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    submission_id BIGINT UNSIGNED NOT NULL,
    note VARCHAR(500) NULL,
    decided_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_reviewer_actions_reviewer (reviewer_user_id, decided_at),
    KEY ix_reviewer_actions_item (item_type, item_id),
    CONSTRAINT fk_reviewer_actions_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id),
    CONSTRAINT fk_reviewer_actions_submission FOREIGN KEY (submission_id) REFERENCES submissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When an overturned appeal cannot be paid from escrow or the advertiser's
-- wallet, the platform fronts the shortfall from system:advertiser_receivable
-- and the advertiser owes it (shown to admins; no automatic penalty).
CREATE TABLE advertiser_debts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    advertiser_user_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    appeal_id BIGINT UNSIGNED NOT NULL,
    amount_kobo BIGINT NOT NULL,
    status ENUM('open', 'collected', 'written_off', 'cancelled') NOT NULL DEFAULT 'open',
    ledger_transaction_id BIGINT UNSIGNED NOT NULL,
    resolve_transaction_id BIGINT UNSIGNED NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_advertiser_debts_appeal (appeal_id),
    KEY ix_advertiser_debts_advertiser (advertiser_user_id, status),
    KEY ix_advertiser_debts_status (status, id),
    CONSTRAINT fk_advertiser_debts_advertiser FOREIGN KEY (advertiser_user_id) REFERENCES users(id),
    CONSTRAINT fk_advertiser_debts_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
    CONSTRAINT fk_advertiser_debts_appeal FOREIGN KEY (appeal_id) REFERENCES submission_appeals(id),
    CONSTRAINT fk_advertiser_debts_tx FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_advertiser_debts_resolve_tx FOREIGN KEY (resolve_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_advertiser_debts_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:advertiser_receivable', 'Appeal payouts fronted by the platform (owed by advertisers)', 'system', 'debit', 0);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('appeals.window_hours', '72'),
    ('reviews.claim_minutes', '30'),
    ('appeals.flag_min_overturns', '3'),
    ('appeals.flag_rate_percent', '30'),
    ('appeals.flag_window_days', '30');

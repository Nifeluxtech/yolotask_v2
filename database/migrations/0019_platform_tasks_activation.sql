-- YOLOTASK V2: YOLOTASK tasks (platform-provided tasks in bundles) and the
-- optional account activation fee.
--
-- Few advertisers means few tasks, so YOLOTASK adds its own tasks: social
-- accounts ("bundles": one platform, one account name, its profile link) with
-- several tasks under each (follow the profile, like post A, comment on post
-- B ...). Each task is a campaign row with is_platform = 1 and bundle_id set,
-- owned by the closed system account "YOLOTASK" (setting
-- platform_tasks.owner_user_id), so the normal task flow applies: reserve,
-- submit proof, reviewer approve / reject, appeals, auto-approve, levels,
-- referral milestones and leaderboards. Earners see them in the normal task
-- feed after advertisers' tasks, with no special label.
--   * No escrow, no budget, no slot limit; each task once per earner.
--   * Reward = the task type's normal earner reward x
--     platform_tasks.reward_percent (default 50 = half), rounded down to the
--     kobo; paid by the platform: Dr system:platform_task_payouts / Cr earner
--     wallet (or system:reward_hold). Price = reward, so no margin.
--
-- Account activation (activation.required, default 0 = OFF): when an admin
-- switches it on, an earner who has not activated cannot see or start ANY
-- task until they pay activation.fee_kobo (default ₦500) once by bank
-- transfer (AfrStar collection order, reference YTA...). The fee is platform
-- revenue: Dr system:afrstar_clearing / Cr system:activation_revenue. It
-- never touches the earner's wallet. Activated earners stay activated.
--
-- Additive and safe with the previous release's code: the old code never
-- reads the new columns or tables and never sets is_platform.
-- Numbering: follows 0017_retire_premium.sql; depends on 0003/0004 (ledger,
-- settings, payment reviews), 0005/0006 (campaigns, submissions).

-- 1. Bundles: one social account with several tasks under it.
CREATE TABLE platform_task_bundles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bundle_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    platform_id INT UNSIGNED NOT NULL,
    account_name VARCHAR(80) NOT NULL,
    profile_url VARCHAR(500) NULL,
    status ENUM('live', 'paused') NOT NULL DEFAULT 'live',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_task_bundles_key (bundle_key),
    KEY ix_platform_task_bundles_status (status, id),
    CONSTRAINT fk_platform_task_bundles_platform FOREIGN KEY (platform_id) REFERENCES platforms(id),
    CONSTRAINT fk_platform_task_bundles_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Campaigns: the platform-task flag, the bundle, and money rules that allow a platform row.
ALTER TABLE campaigns
    ADD COLUMN is_platform TINYINT(1) NOT NULL DEFAULT 0 AFTER tier,
    ADD COLUMN bundle_id BIGINT UNSIGNED NULL AFTER is_platform,
    ADD KEY ix_campaigns_platform (is_platform, status, id),
    ADD UNIQUE KEY uq_campaigns_bundle_task (bundle_id, task_type_id, link_hash),
    ADD CONSTRAINT fk_campaigns_bundle FOREIGN KEY (bundle_id) REFERENCES platform_task_bundles(id);

ALTER TABLE campaigns DROP CONSTRAINT chk_campaigns_money;

ALTER TABLE campaigns
    ADD CONSTRAINT chk_campaigns_money CHECK (
        (is_platform = 1 AND bundle_id IS NOT NULL AND unit_reward_kobo > 0 AND unit_price_kobo = unit_reward_kobo AND budget_kobo = 0 AND funded_kobo = 0)
        OR (is_platform = 0 AND bundle_id IS NULL AND unit_reward_kobo > 0 AND unit_price_kobo > unit_reward_kobo
            AND (budget_kobo = unit_price_kobo * quantity OR (repriced_at IS NOT NULL AND budget_kobo > unit_price_kobo * quantity))));

-- 3. The system account that owns YOLOTASK tasks (closed: it can never sign in; '!' is not a password hash).
INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at, closed_at)
    SELECT 'YOLOTASK', 'platform-tasks@system.yolotask.invalid', '!', 'advertiser', 'closed', UTC_TIMESTAMP(), UTC_TIMESTAMP()
    FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'platform-tasks@system.yolotask.invalid');

INSERT INTO settings (setting_key, setting_value)
    SELECT 'platform_tasks.owner_user_id', CAST(id AS CHAR) FROM users WHERE email = 'platform-tasks@system.yolotask.invalid'
    ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- 4. Ledger accounts.
INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:activation_revenue', 'Account activation fees (revenue)', 'system', 'credit', 0),
    ('system:platform_task_payouts', 'YOLOTASK task rewards paid by the platform (expense)', 'system', 'debit', 0);

-- 5. Activation payments (AfrStar collection orders, reference YTA...).
CREATE TABLE activation_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_sn VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'afrstar',
    amount_kobo BIGINT UNSIGNED NOT NULL,
    status ENUM('created', 'pending', 'paid', 'review', 'rejected', 'expired', 'failed') NOT NULL DEFAULT 'created',
    platform_osn VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pay_url VARCHAR(500) NULL,
    amount_paid_kobo BIGINT UNSIGNED NULL,
    revenue_kobo BIGINT UNSIGNED NULL,
    provider_reference VARCHAR(120) NULL,
    provider_message VARCHAR(255) NULL,
    ledger_transaction_id BIGINT UNSIGNED NULL,
    paid_at DATETIME NULL,
    verified_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_activation_payments_sn (order_sn),
    UNIQUE KEY uq_activation_payments_tx (ledger_transaction_id),
    KEY ix_activation_payments_platform (platform_osn),
    KEY ix_activation_payments_user (user_id, id),
    KEY ix_activation_payments_status_time (status, created_at),
    CONSTRAINT fk_activation_payments_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_activation_payments_ledger FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_activation_payments_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. One activation per earner, for good (switching activation off and on again keeps it).
CREATE TABLE account_activations (
    user_id BIGINT UNSIGNED NOT NULL,
    activation_payment_id BIGINT UNSIGNED NULL,
    source ENUM('payment', 'review', 'admin') NOT NULL DEFAULT 'payment',
    amount_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,
    activated_by_user_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_account_activations_payment (activation_payment_id),
    KEY ix_account_activations_time (activated_at),
    CONSTRAINT fk_account_activations_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_account_activations_payment FOREIGN KEY (activation_payment_id) REFERENCES activation_payments(id),
    CONSTRAINT fk_account_activations_admin FOREIGN KEY (activated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Activation payments share the admin payment review queue with top-ups.
ALTER TABLE payment_reviews
    MODIFY kind ENUM('amount_mismatch', 'unmatched_callback', 'payout_conflict', 'activation_duplicate') NOT NULL,
    ADD COLUMN activation_payment_id BIGINT UNSIGNED NULL AFTER payment_order_id,
    ADD KEY ix_payment_reviews_activation (activation_payment_id),
    ADD CONSTRAINT fk_payment_reviews_activation FOREIGN KEY (activation_payment_id) REFERENCES activation_payments(id);

-- 8. Settings. Account activation ships OFF: every earner sees every task until an admin switches it on.
INSERT INTO settings (setting_key, setting_value) VALUES
    ('activation.required', '0'),
    ('activation.fee_kobo', '50000'),
    ('platform_tasks.reward_percent', '50')
    ON DUPLICATE KEY UPDATE setting_value = setting_value;

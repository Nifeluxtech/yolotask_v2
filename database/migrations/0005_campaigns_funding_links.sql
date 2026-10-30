-- YOLOTASK V2 Phase 4: catalog (platforms, task types and prices), campaigns,
-- versioned instructions, campaign funding (escrow) and advertiser settings,
-- migration 0005. Additive only; safe to run once after 0004.
-- Money is integer kobo (BIGINT). Each funded campaign gets its own
-- credit-normal ledger account (kind campaign_escrow); system:revenue holds
-- the platform margin and system:reward_hold holds approved rewards that are
-- waiting for the reward hold window.

CREATE TABLE platforms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(60) NOT NULL,
    domains VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platforms_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platforms (slug, name, domains, sort_order) VALUES
    ('instagram', 'Instagram', 'instagram.com,instagr.am', 10),
    ('tiktok', 'TikTok', 'tiktok.com', 20),
    ('x', 'X (Twitter)', 'x.com,twitter.com', 30),
    ('facebook', 'Facebook', 'facebook.com,fb.com,fb.watch', 40),
    ('youtube', 'YouTube', 'youtube.com,youtu.be', 50),
    ('whatsapp', 'WhatsApp', 'whatsapp.com,wa.me', 60),
    ('telegram', 'Telegram', 't.me,telegram.me,telegram.org', 70),
    ('threads', 'Threads', 'threads.net,threads.com', 80);

-- Prices: base = untargeted campaign, premium = targeted campaign.
-- price = what the advertiser pays per approved task, reward = what the earner
-- receives; the difference is platform revenue.
CREATE TABLE task_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(60) NOT NULL,
    proof_hint VARCHAR(255) NOT NULL DEFAULT '',
    base_price_kobo BIGINT NOT NULL,
    base_reward_kobo BIGINT NOT NULL,
    premium_price_kobo BIGINT NOT NULL,
    premium_reward_kobo BIGINT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_task_types_slug (slug),
    CONSTRAINT fk_task_types_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Premium = base x 1.5 rounded half-up to whole naira.
INSERT INTO task_types (slug, name, proof_hint, base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo, sort_order) VALUES
    ('follow', 'Follow', 'Screenshot of the profile showing you follow it.', 2500, 1500, 3800, 2300, 10),
    ('like', 'Like', 'Screenshot of the post showing your like.', 1500, 1000, 2300, 1500, 20),
    ('comment', 'Comment', 'Screenshot showing your comment under the post.', 4000, 2500, 6000, 3800, 30),
    ('share', 'Share / Repost', 'Screenshot of your share or repost.', 4000, 2500, 6000, 3800, 40),
    ('subscribe', 'Subscribe', 'Screenshot showing you are subscribed.', 3000, 2000, 4500, 3000, 50),
    ('join', 'Join group / channel', 'Screenshot showing you joined the group or channel.', 3000, 2000, 4500, 3000, 60),
    ('watch', 'Watch video', 'Screenshot of the video at the end, showing your account.', 2000, 1200, 3000, 1800, 70);

CREATE TABLE campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    advertiser_user_id BIGINT UNSIGNED NOT NULL,
    platform_id INT UNSIGNED NOT NULL,
    task_type_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    link_url VARCHAR(500) NOT NULL,
    link_normalized VARCHAR(500) NOT NULL,
    link_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instructions TEXT NOT NULL,
    proof_requirements VARCHAR(500) NOT NULL,
    instructions_version INT UNSIGNED NOT NULL DEFAULT 1,
    tier ENUM('basic', 'targeted') NOT NULL DEFAULT 'basic',
    unit_price_kobo BIGINT NOT NULL,
    unit_reward_kobo BIGINT NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    budget_kobo BIGINT NOT NULL,
    funded_kobo BIGINT NOT NULL DEFAULT 0,
    spent_kobo BIGINT NOT NULL DEFAULT 0,
    refunded_kobo BIGINT NOT NULL DEFAULT 0,
    refund_seq INT UNSIGNED NOT NULL DEFAULT 0,
    approved_count INT UNSIGNED NOT NULL DEFAULT 0,
    pending_count INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
    auto_approve_hours INT UNSIGNED NOT NULL DEFAULT 48,
    status ENUM('draft', 'pending_approval', 'live', 'paused', 'rejected', 'completed', 'cancelled', 'ended') NOT NULL DEFAULT 'draft',
    status_reason VARCHAR(255) NULL,
    escrow_account_id BIGINT UNSIGNED NULL,
    ends_at DATETIME NULL,
    submitted_at DATETIME NULL,
    approved_at DATETIME NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_campaigns_advertiser (advertiser_user_id, status, id),
    KEY ix_campaigns_status (status, tier, id),
    KEY ix_campaigns_link (link_hash, task_type_id, status),
    KEY ix_campaigns_ends (status, ends_at),
    CONSTRAINT fk_campaigns_advertiser FOREIGN KEY (advertiser_user_id) REFERENCES users(id),
    CONSTRAINT fk_campaigns_platform FOREIGN KEY (platform_id) REFERENCES platforms(id),
    CONSTRAINT fk_campaigns_task_type FOREIGN KEY (task_type_id) REFERENCES task_types(id),
    CONSTRAINT fk_campaigns_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_campaigns_quantity CHECK (quantity BETWEEN 1 AND 100000),
    CONSTRAINT chk_campaigns_money CHECK (unit_reward_kobo > 0 AND unit_price_kobo > unit_reward_kobo AND budget_kobo = unit_price_kobo * quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instruction history: every edit is a new version; submissions record the
-- version the earner worked from.
CREATE TABLE campaign_instructions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    instructions TEXT NOT NULL,
    proof_requirements VARCHAR(500) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaign_instructions_version (campaign_id, version),
    CONSTRAINT fk_campaign_instructions_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_instructions_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Funding history (escrow lock and every refund), each tied to its ledger transaction.
CREATE TABLE campaign_funding_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    event ENUM('lock', 'refund') NOT NULL,
    amount_kobo BIGINT NOT NULL,
    ledger_transaction_id BIGINT UNSIGNED NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaign_funding_tx (ledger_transaction_id),
    KEY ix_campaign_funding_campaign (campaign_id, id),
    CONSTRAINT fk_campaign_funding_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
    CONSTRAINT fk_campaign_funding_tx FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE advertiser_settings (
    user_id BIGINT UNSIGNED NOT NULL,
    auto_approve_hours INT UNSIGNED NOT NULL DEFAULT 48,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_advertiser_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ledger_accounts
    MODIFY kind ENUM('system', 'wallet_available', 'wallet_held', 'campaign_escrow') NOT NULL,
    ADD COLUMN campaign_id BIGINT UNSIGNED NULL AFTER wallet_id,
    ADD UNIQUE KEY uq_ledger_accounts_campaign (campaign_id),
    ADD CONSTRAINT fk_ledger_accounts_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id);

INSERT INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:revenue', 'Platform revenue (task margins)', 'system', 'credit', 0),
    ('system:reward_hold', 'Approved rewards waiting for release', 'system', 'credit', 0);

INSERT INTO settings (setting_key, setting_value) VALUES
    ('tasks.reservation_minutes', '60'),
    ('tasks.max_active_reservations', '3'),
    ('tasks.reward_hold_hours', '0'),
    ('tasks.resubmission_cap', '1'),
    ('tasks.stale_hours', '24'),
    ('tasks.auto_approve_default_hours', '48'),
    ('tasks.auto_approve_min_hours', '12'),
    ('tasks.auto_approve_max_hours', '336'),
    ('tasks.proof_max_bytes', '2097152'),
    ('campaigns.min_quantity', '1'),
    ('campaigns.max_quantity', '100000');

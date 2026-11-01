-- YOLOTASK V2 Phase 6: referrals (earner milestones + advertiser deposit
-- commission), earner levels, admin bonuses, in-app notifications and admin
-- announcements, migration 0009 (referrals_levels_notifications).
-- Additive only; safe to run once after 0008.
-- Plain DDL/DML only: no triggers, procedures, events or DEFINER clauses, so it
-- runs as the ordinary cPanel database user (same style as 0005-0008).
-- File order note: this migration replaces the two old placeholders
-- 0007_growth_referrals_bonuses_levels.sql and 0008_notifications_announcements.sql
-- (deleted), and the later placeholders moved up one number
-- (0010_virtual_number_orders.sql, 0011_indexes_constraints_finalization.sql)
-- so every number has exactly one executable file or one placeholder.
-- Interests (Nov 2026): the interest catalog that the old placeholder
-- 0002_settings_catalog_interests.sql reserved is implemented here (that
-- placeholder was deleted; settings and the task catalog already live in
-- 0002_account_security / 0005). Folded into 0009 because 0009 has not been
-- applied on staging yet.

-- Every earner and advertiser gets a referral code (created lazily by the
-- app; NULL until first needed). email_normalized is the address with
-- "+alias" removed (and dots removed for Gmail) so one inbox cannot refer
-- itself through aliases.
ALTER TABLE users
    ADD COLUMN referral_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER email,
    ADD COLUMN email_normalized VARCHAR(254) NULL AFTER referral_code,
    ADD UNIQUE KEY uq_users_referral_code (referral_code),
    ADD KEY ix_users_email_normalized (email_normalized);

UPDATE users SET email_normalized = CASE
    WHEN LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ('gmail.com', 'googlemail.com')
        THEN CONCAT(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(email), '@', 1), '+', 1), '.', ''), '@gmail.com')
    ELSE CONCAT(SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(email), '@', 1), '+', 1), '@', LOWER(SUBSTRING_INDEX(email, '@', -1)))
END
WHERE email_normalized IS NULL;

-- Who referred whom. Set once at registration, never changed. Shared
-- networks are normal (family Wi-Fi, campus, one mobile carrier IP), so each
-- referrer gets an allowance (setting referrals.ip_allowance, default 3) of
-- referred sign-ups per IP address and per device; the referrer's own IP
-- counts as that IP. The sign-up past the allowance, and any sign-up on the
-- referrer's own device (same device AND network as one of the referrer's
-- recent sessions), is "flagged": nothing is paid until an admin approves it
-- ("active") or rejects it for good ("rejected").
CREATE TABLE referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referrer_user_id BIGINT UNSIGNED NOT NULL,
    referee_user_id BIGINT UNSIGNED NOT NULL,
    referee_role ENUM('earner', 'advertiser') NOT NULL,
    code_used VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('active', 'flagged', 'rejected') NOT NULL DEFAULT 'active',
    flag_reason VARCHAR(255) NULL,
    signup_ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    signup_agent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_referrals_referee (referee_user_id),
    KEY ix_referrals_referrer (referrer_user_id, id),
    KEY ix_referrals_status (status, referee_role, id),
    KEY ix_referrals_ip (referrer_user_id, signup_ip_hash),
    KEY ix_referrals_agent (referrer_user_id, signup_agent_hash),
    CONSTRAINT fk_referrals_referrer FOREIGN KEY (referrer_user_id) REFERENCES users(id),
    CONSTRAINT fk_referrals_referee FOREIGN KEY (referee_user_id) REFERENCES users(id),
    CONSTRAINT fk_referrals_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Milestones for referred earners: when the referee reaches task_count
-- approved tasks the referrer earns reward_kobo. Milestones stack; each is
-- paid once per referee. Admin-editable, unlimited.
CREATE TABLE referral_milestones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_count INT UNSIGNED NOT NULL,
    reward_kobo BIGINT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_referral_milestones_active (is_active, task_count),
    CONSTRAINT fk_referral_milestones_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO referral_milestones (task_count, reward_kobo) VALUES (10, 10000), (25, 20000);

-- Every referral payment: a milestone (unique per referral + milestone) or a
-- deposit commission (unique per top-up order). "skipped" = nothing was due
-- (e.g. commission rounded to zero), kept so the catch-up sweep stops.
CREATE TABLE referral_payouts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    referrer_user_id BIGINT UNSIGNED NOT NULL,
    referee_user_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('milestone', 'commission') NOT NULL,
    milestone_id BIGINT UNSIGNED NULL,
    milestone_task_count INT UNSIGNED NULL,
    payment_order_id BIGINT UNSIGNED NULL,
    base_kobo BIGINT NOT NULL DEFAULT 0,
    rate_bps INT UNSIGNED NULL,
    amount_kobo BIGINT NOT NULL,
    status ENUM('paid', 'reversed', 'skipped') NOT NULL DEFAULT 'paid',
    ledger_transaction_id BIGINT UNSIGNED NULL,
    reverse_transaction_id BIGINT UNSIGNED NULL,
    reversed_by_user_id BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reverse_reason VARCHAR(255) NULL,
    flag_note VARCHAR(255) NULL,
    flagged_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_referral_payouts_milestone (referral_id, milestone_id),
    UNIQUE KEY uq_referral_payouts_order (payment_order_id),
    KEY ix_referral_payouts_referrer (referrer_user_id, id),
    KEY ix_referral_payouts_kind (kind, status, id),
    KEY ix_referral_payouts_flag (flagged_at),
    CONSTRAINT fk_referral_payouts_referral FOREIGN KEY (referral_id) REFERENCES referrals(id),
    CONSTRAINT fk_referral_payouts_referrer FOREIGN KEY (referrer_user_id) REFERENCES users(id),
    CONSTRAINT fk_referral_payouts_referee FOREIGN KEY (referee_user_id) REFERENCES users(id),
    CONSTRAINT fk_referral_payouts_milestone FOREIGN KEY (milestone_id) REFERENCES referral_milestones(id),
    CONSTRAINT fk_referral_payouts_order FOREIGN KEY (payment_order_id) REFERENCES payment_orders(id),
    CONSTRAINT fk_referral_payouts_tx FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_referral_payouts_reverse_tx FOREIGN KEY (reverse_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_referral_payouts_reverser FOREIGN KEY (reversed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Earner levels: reached by approved-task count and approval rate. Each
-- level sets how many tasks the earner may hold at once and whether premium
-- (targeted) tasks appear in their feed.
CREATE TABLE earner_levels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(40) NOT NULL,
    min_approved INT UNSIGNED NOT NULL DEFAULT 0,
    min_approval_rate TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_active_reservations TINYINT UNSIGNED NOT NULL DEFAULT 3,
    premium_access TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_earner_levels_order (is_active, min_approved),
    CONSTRAINT fk_earner_levels_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO earner_levels (name, min_approved, min_approval_rate, max_active_reservations, premium_access) VALUES
    ('Starter', 0, 0, 3, 1),
    ('Bronze', 50, 80, 4, 1),
    ('Silver', 200, 85, 5, 1),
    ('Gold', 500, 90, 6, 1);

-- In-app inbox. dedupe_key makes a repeated event (double callback, retried
-- cron) a no-op for the inbox and its email.
CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(140) NOT NULL,
    body VARCHAR(500) NOT NULL,
    url VARCHAR(255) NULL,
    dedupe_key VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_dedupe (user_id, dedupe_key),
    KEY ix_notifications_inbox (user_id, read_at, id),
    KEY ix_notifications_prune (read_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user email switches by category (no row = the category default).
-- Security emails are not listed here: they are always sent.
CREATE TABLE notification_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email_enabled TINYINT(1) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, category),
    CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin announcements: a banner while live plus one inbox item per user in
-- the audience (delivered when the start time arrives).
CREATE TABLE announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(140) NOT NULL,
    body VARCHAR(1000) NOT NULL,
    audience ENUM('all', 'earner', 'advertiser') NOT NULL DEFAULT 'all',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    status ENUM('active', 'withdrawn') NOT NULL DEFAULT 'active',
    delivered_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_announcements_live (status, starts_at, ends_at),
    CONSTRAINT fk_announcements_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expense accounts (debit-normal): referral milestone bonuses, advertiser
-- deposit commissions and admin one-off bonuses. A reversal credits them back.
INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:referral_bonus', 'Referral milestone bonuses paid (expense)', 'system', 'debit', 0),
    ('system:referral_commission', 'Referral deposit commissions paid (expense)', 'system', 'debit', 0),
    ('system:bonuses', 'Admin one-off bonuses paid (expense)', 'system', 'debit', 0);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('referrals.enabled', '1'),
    ('referrals.commission_bps', '500'),
    ('levels.enabled', '1'),
    ('notifications.retention_days', '90'),
    ('referrals.ip_allowance', '3');

-- Interests: an admin-managed list. Earners pick 1-3; every new campaign
-- picks 1-3. Premium (targeted) tasks are shown to and reservable by an
-- earner only when at least one active interest overlaps (a campaign with
-- no active interest, e.g. one made before interests existed, matches every
-- earner who has picked at least one). Disabled interests stay on old rows
-- (so re-enabling restores them) but are ignored everywhere.
CREATE TABLE interests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(40) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_interests_name (name),
    KEY ix_interests_order (is_active, sort_order, id),
    CONSTRAINT fk_interests_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO interests (name, sort_order) VALUES
    ('Music & Entertainment', 10),
    ('Sports & Betting', 20),
    ('Fashion & Beauty', 30),
    ('Tech & Gadgets', 40),
    ('Crypto & Finance', 50),
    ('Food & Lifestyle', 60),
    ('Education', 70),
    ('Religion & Inspiration', 80),
    ('Business & Marketing', 90),
    ('Gaming', 100),
    ('Comedy', 110),
    ('News & Politics', 120);

CREATE TABLE user_interests (
    user_id BIGINT UNSIGNED NOT NULL,
    interest_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, interest_id),
    KEY ix_user_interests_interest (interest_id),
    CONSTRAINT fk_user_interests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_interests_interest FOREIGN KEY (interest_id) REFERENCES interests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_interests (
    campaign_id BIGINT UNSIGNED NOT NULL,
    interest_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id, interest_id),
    KEY ix_campaign_interests_interest (interest_id, campaign_id),
    CONSTRAINT fk_campaign_interests_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_interests_interest FOREIGN KEY (interest_id) REFERENCES interests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- YOLOTASK V2 Phase 7: leaderboard indexes and leadership reward history.
--
-- Numbering: 0010 and 0011 are reserved placeholders (marked as not to be run) that
-- scripts/migrate.php skips without recording, so this file is the next
-- migration applied after 0009. If 0010/0011 are filled in later, the runner
-- still applies them on any database where they are pending (it runs every
-- unrecorded file in name order), so nothing depends on the gap.

-- Leaderboard range scans: approved submissions by approval time, and paid
-- referral payouts by credit time.
ALTER TABLE submissions
    ADD KEY ix_submissions_status_decided (status, decided_at, earner_user_id);

ALTER TABLE referral_payouts
    ADD KEY ix_referral_payouts_status_created (status, created_at, referrer_user_id);

-- Leadership rewards paid from the admin leaderboard. The money itself is an
-- ordinary admin bonus (Dr system:bonuses / Cr wallet, ledger key
-- bonus:<admin>:<form>); this row is the reward history (one per ledger
-- transaction, so a repeated form adds nothing).
CREATE TABLE leaderboard_rewards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    admin_user_id BIGINT UNSIGNED NULL,
    board ENUM('workers', 'referrers') NOT NULL,
    period ENUM('week', 'month') NOT NULL,
    period_label VARCHAR(40) NOT NULL,
    rank_at_reward INT UNSIGNED NULL,
    amount_kobo BIGINT NOT NULL,
    note VARCHAR(140) NOT NULL,
    ledger_transaction_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leaderboard_rewards_tx (ledger_transaction_id),
    KEY ix_leaderboard_rewards_user (user_id, id),
    CONSTRAINT fk_leaderboard_rewards_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_leaderboard_rewards_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leaderboard_rewards_tx FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

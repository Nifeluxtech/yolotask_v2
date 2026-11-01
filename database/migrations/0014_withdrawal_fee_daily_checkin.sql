-- YOLOTASK V2: earner withdrawal fee and daily check-in, migration 0014.
--
-- Withdrawal fee. Base rate money.withdraw_fee_bps (default 1000 = 10 %,
-- admin 0-50 % in Money settings); each earner level may set a lower rate
-- (earner_levels.withdrawal_fee_bps, NULL = use the base rate; never above
-- the base, also enforced at request time). fee = floor(amount x bps / 10000),
-- rounded DOWN in the earner's favour. The rate and fee are stored on the
-- withdrawal when it is requested (withdrawals.fee_bps, fee_kobo) and never
-- recalculated, so a later admin change does not affect queued withdrawals.
-- Withdrawals created before this migration keep fee 0 (backfilled below).
-- The minimum/maximum and 24-hour limits apply to the gross amount.
-- Ledger: request = Dr available / Cr held (gross, unchanged); reject or
-- failed payout = Dr held / Cr available (gross: the full amount incl. fee
-- goes back); paid = Dr held (gross) / Cr system:afrstar_clearing (net sent)
-- / Cr system:withdrawal_fees (fee, only when > 0). The fee is recognised
-- only when the payout is confirmed, so a refund never has to undo it.
--
-- Daily check-in. One per earner per Africa/Lagos calendar day (unique
-- user + lagos_date). Pays the amount set on the earner's level
-- (earner_levels.checkin_reward_kobo, default 0 = off for that level) when
-- the global switch checkin.enabled = 1 (default 0 = off) and the email is
-- confirmed. Ledger: Dr system:checkin_rewards / Cr earner available, key
-- checkin:<userId>:<YYYY-MM-DD>.
--
-- Numbering: follows 0013_deposit_cashback.sql (0010/0011 stay reserved
-- placeholders that the runner skips).

ALTER TABLE withdrawals
    ADD COLUMN fee_bps INT UNSIGNED NOT NULL DEFAULT 0 AFTER fee_kobo,
    ADD COLUMN fee_level_id BIGINT UNSIGNED NULL AFTER fee_bps;

-- Pre-0014 withdrawals were free: make that explicit (fee 0 at 0 bps).
UPDATE withdrawals SET fee_kobo = 0, fee_bps = 0 WHERE fee_kobo <> 0 OR fee_bps <> 0;

ALTER TABLE earner_levels
    ADD COLUMN withdrawal_fee_bps INT UNSIGNED NULL DEFAULT NULL AFTER premium_access,
    ADD COLUMN checkin_reward_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER withdrawal_fee_bps;

CREATE TABLE daily_checkins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    lagos_date DATE NOT NULL,
    level_id BIGINT UNSIGNED NULL,
    amount_kobo BIGINT UNSIGNED NOT NULL,
    streak INT UNSIGNED NOT NULL DEFAULT 1,
    ledger_transaction_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_checkins_user_day (user_id, lagos_date),
    UNIQUE KEY uq_daily_checkins_tx (ledger_transaction_id),
    KEY ix_daily_checkins_day (lagos_date),
    CONSTRAINT fk_daily_checkins_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_daily_checkins_tx FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:withdrawal_fees', 'Earner withdrawal fees (revenue)', 'system', 'credit', 0),
    ('system:checkin_rewards', 'Daily check-in rewards paid (expense)', 'system', 'debit', 0);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('money.withdraw_fee_bps', '1000'),
    ('checkin.enabled', '0');

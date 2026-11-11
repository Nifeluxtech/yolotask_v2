-- YOLOTASK V2 migration 0020: referral bonus when a referred earner activates.
-- When an earner who signed up with a referral code activates their account
-- (pays the one-time activation fee, or an admin confirms it), the referrer
-- is paid referrals.activation_bonus_kobo (default ₦100) once, from
-- system:referral_bonus, like milestone bonuses. 0 switches it off.
-- Only activations from referrals.activation_bonus_since (UTC) on are paid,
-- so turning this on never pays for activations made before the fee went live.
-- Additive only.

ALTER TABLE referral_payouts
    MODIFY kind ENUM('milestone', 'commission', 'activation') NOT NULL;

-- One activation bonus per referral.
ALTER TABLE referral_payouts
    ADD COLUMN activation_referral_id BIGINT UNSIGNED AS (IF(kind = 'activation', referral_id, NULL)) STORED,
    ADD UNIQUE KEY uq_referral_payouts_activation (activation_referral_id);

INSERT INTO settings (setting_key, setting_value) VALUES
    ('referrals.activation_bonus_kobo', '10000'),
    ('referrals.activation_bonus_since', '2026-11-11 11:00:00')
    ON DUPLICATE KEY UPDATE setting_value = setting_value;

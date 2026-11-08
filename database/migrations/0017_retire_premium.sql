-- YOLOTASK V2: retire premium (targeted) campaigns and interests.
--
-- From this release every campaign is standard: the catalog's base price and
-- base reward. The code no longer reads or writes campaigns.tier,
-- task_types.premium_price_kobo / premium_reward_kobo,
-- earner_levels.premium_access or the interests, user_interests and
-- campaign_interests tables. They are left in place, dormant, so the change
-- can be reversed; a later migration may drop them once that is no longer
-- wanted. Only scripts/retire-premium.php still reads campaigns.tier, once.
--
-- Additive and safe with the previous release's code (which still writes
-- the premium columns explicitly and never sets the new columns).
-- Numbering: follows 0016_custom_tasks.sql; depends on 0005/0006 (and 0016:
-- custom-task campaigns are left alone unless they were premium).

-- 1. New task types are created without premium prices.
ALTER TABLE task_types
    MODIFY premium_price_kobo BIGINT NOT NULL DEFAULT 0,
    MODIFY premium_reward_kobo BIGINT NOT NULL DEFAULT 0;

-- 2. The price an earner was promised when they reserved a slot. Submitting
--    uses it, so a later price change (the premium retirement) never lowers
--    the reward of a task already started. NULL = reserved before this
--    migration: the campaign's current price applies, as before.
ALTER TABLE task_reservations
    ADD COLUMN unit_price_kobo BIGINT NULL AFTER started_count,
    ADD COLUMN unit_reward_kobo BIGINT NULL AFTER unit_price_kobo;

-- 3. A funded campaign whose per-task price was lowered after funding
--    (scripts/retire-premium.php) keeps budget_kobo = what the advertiser
--    funded, so budget_kobo is then above unit_price_kobo x quantity; the
--    difference for untaken slots was refunded (refunded_kobo). Every other
--    campaign still needs budget_kobo = unit_price_kobo x quantity exactly.
ALTER TABLE campaigns
    ADD COLUMN repriced_at DATETIME NULL AFTER closed_at;

ALTER TABLE campaigns DROP CONSTRAINT chk_campaigns_money;

ALTER TABLE campaigns
    ADD CONSTRAINT chk_campaigns_money CHECK (unit_reward_kobo > 0 AND unit_price_kobo > unit_reward_kobo
        AND (budget_kobo = unit_price_kobo * quantity OR (repriced_at IS NOT NULL AND budget_kobo > unit_price_kobo * quantity)));

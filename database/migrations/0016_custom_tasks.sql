-- Custom tasks: advertisers can name their own app or site, describe their own
-- task and set their own price per task. Additive only; safe to run once after 0015.
-- A campaign on the 'custom' platform carries custom_platform_name; one on the
-- 'custom' task type carries custom_task_name and an advertiser-set price, with
-- the earner reward taken as campaigns.custom_reward_percent of it (rounded down).

ALTER TABLE campaigns
    ADD COLUMN custom_platform_name VARCHAR(60) NULL AFTER task_type_id,
    ADD COLUMN custom_task_name VARCHAR(80) NULL AFTER custom_platform_name;

INSERT INTO platforms (slug, name, domains, is_active, sort_order) VALUES
    ('custom', 'Other app / website', '', 1, 900)
    ON DUPLICATE KEY UPDATE domains = '', is_active = 1;

INSERT INTO task_types (slug, name, proof_hint, base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo, is_active, sort_order) VALUES
    ('custom', 'Custom task (set your own price)', 'Screenshot that shows you completed the task.', 0, 0, 0, 0, 1, 900)
    ON DUPLICATE KEY UPDATE is_active = 1;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('campaigns.custom_enabled', '1'),
    ('campaigns.custom_min_price_naira', '100'),
    ('campaigns.custom_reward_percent', '80')
    ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- YOLOTASK V2 read-only pre-release checks. Rows = problems.
-- Run the three *_reconciliation.sql files as well.

-- 1. Expected migrations applied (through 0006).
SELECT 'missing_migration' AS problem, m.f AS filename FROM (
    SELECT '0001_users_sessions_security.sql' AS f UNION ALL SELECT '0002_account_security.sql' UNION ALL SELECT '0003_wallets_ledger.sql'
    UNION ALL SELECT '0004_payments_banks_withdrawals.sql' UNION ALL SELECT '0005_campaigns_funding_links.sql' UNION ALL SELECT '0006_tasks_submissions_proofs_appeals.sql'
) m LEFT JOIN schema_migrations s ON s.filename = m.f WHERE s.filename IS NULL;

-- 2. System ledger accounts exist.
SELECT 'missing_system_account' AS problem, x.code FROM (
    SELECT 'system:afrstar_clearing' AS code UNION ALL SELECT 'system:adjustments' UNION ALL SELECT 'system:revenue' UNION ALL SELECT 'system:reward_hold'
) x LEFT JOIN ledger_accounts a ON a.code = x.code WHERE a.id IS NULL;

-- 3. At least one active admin.
SELECT 'no_active_admin' AS problem FROM (SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND status = 'active') t WHERE t.n = 0;

-- 4. Catalog priced sensibly (reward below price, both positive).
SELECT 'bad_price' AS problem, slug, base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo FROM task_types
WHERE base_reward_kobo <= 0 OR premium_reward_kobo <= 0 OR base_reward_kobo >= base_price_kobo OR premium_reward_kobo >= premium_price_kobo;

-- 5. Bank account numbers encrypted at rest, enc2: (AES-256-GCM) or legacy enc1: (libsodium).
--    Run scripts/encrypt-bank-details.php if not.
SELECT 'plaintext_bank_number' AS problem, 'bank_accounts' AS tbl, COUNT(*) AS n FROM bank_accounts WHERE account_number NOT LIKE 'enc1:%' AND account_number NOT LIKE 'enc2:%' HAVING n > 0;
SELECT 'plaintext_bank_number' AS problem, 'withdrawals' AS tbl, COUNT(*) AS n FROM withdrawals WHERE account_number NOT LIKE 'enc1:%' AND account_number NOT LIKE 'enc2:%' HAVING n > 0;

-- 6. Money settings present and sane.
SELECT 'setting_out_of_range' AS problem, setting_key, setting_value FROM settings
WHERE (setting_key LIKE 'money.%_kobo' AND CAST(setting_value AS SIGNED) <= 0)
   OR (setting_key = 'money.topup_min_kobo' AND CAST(setting_value AS SIGNED) > (SELECT CAST(setting_value AS SIGNED) FROM settings s2 WHERE s2.setting_key = 'money.topup_max_kobo'));

-- 7. Old pending payouts with no callback (also flagged by cron/every-five-minutes.php).
SELECT 'stuck_payout' AS problem, id, order_sn, reviewed_at FROM withdrawals WHERE status = 'processing' AND reviewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR);

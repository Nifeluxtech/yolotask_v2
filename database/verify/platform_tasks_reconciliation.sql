-- YOLOTASK V2 read-only check: YOLOTASK tasks (bundles) and account activation (migration 0019).
-- Every query returns rows ONLY when something is wrong; an empty result for
-- all of them = healthy. Same rules as Account\Activation::reconcile (part of
-- Money\Ledger::reconcile, daily cron).
-- Usage: mysql DBNAME < database/verify/platform_tasks_reconciliation.sql

-- 1. Activation revenue equals the revenue of paid activation payments.
SELECT 'activation_revenue' AS problem, a.balance_kobo, p.paid
FROM ledger_accounts a, (SELECT COALESCE(SUM(revenue_kobo), 0) AS paid FROM activation_payments WHERE status = 'paid') p
WHERE a.code = 'system:activation_revenue' AND a.balance_kobo <> p.paid;

-- 2. Each paid activation payment carries exactly its posting: Dr system:afrstar_clearing / Cr system:activation_revenue.
SELECT 'activation_posting' AS problem, ap.id, ap.order_sn, ap.revenue_kobo, t.amount_kobo, x.clearing, x.revenue
FROM activation_payments ap
LEFT JOIN ledger_transactions t ON t.id = ap.ledger_transaction_id
LEFT JOIN (SELECT e.transaction_id,
                  SUM(CASE WHEN a.code = 'system:afrstar_clearing' AND e.direction = 'debit' THEN e.amount_kobo ELSE 0 END) AS clearing,
                  SUM(CASE WHEN a.code = 'system:activation_revenue' AND e.direction = 'credit' THEN e.amount_kobo ELSE 0 END) AS revenue
           FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id GROUP BY e.transaction_id) x ON x.transaction_id = ap.ledger_transaction_id
WHERE (ap.status = 'paid' AND (t.id IS NULL OR t.type <> 'activation_fee' OR t.amount_kobo <> ap.revenue_kobo OR COALESCE(x.clearing, 0) <> ap.revenue_kobo OR COALESCE(x.revenue, 0) <> ap.revenue_kobo))
   OR (ap.status <> 'paid' AND ap.ledger_transaction_id IS NOT NULL);

-- 3. No activation posting without its payment row, and none ever touched a wallet.
SELECT 'activation_orphan_posting' AS problem, t.id, t.idempotency_key
FROM ledger_transactions t LEFT JOIN activation_payments ap ON ap.ledger_transaction_id = t.id
WHERE t.type = 'activation_fee' AND ap.id IS NULL;
SELECT 'activation_touched_wallet' AS problem, t.id, t.idempotency_key
FROM ledger_transactions t JOIN ledger_entries e ON e.transaction_id = t.id JOIN ledger_accounts a ON a.id = e.account_id
WHERE t.type = 'activation_fee' AND a.kind IN ('wallet_available', 'wallet_held');

-- 4. Every activated earner points at a paid payment of their own.
SELECT 'activation_without_payment' AS problem, pa.user_id, pa.activation_payment_id
FROM account_activations pa LEFT JOIN activation_payments ap ON ap.id = pa.activation_payment_id
WHERE ap.id IS NULL OR ap.status <> 'paid' OR ap.user_id <> pa.user_id;

-- 5. YOLOTASK task payouts equal the rewards of approved YOLOTASK tasks.
SELECT 'platform_task_payouts' AS problem, a.balance_kobo, r.rewards
FROM ledger_accounts a,
     (SELECT COALESCE(SUM(s.unit_reward_kobo), 0) AS rewards FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE c.is_platform = 1 AND s.status = 'approved') r
WHERE a.code = 'system:platform_task_payouts' AND a.balance_kobo <> r.rewards;

-- 6. YOLOTASK tasks never hold escrow and never carry a margin (price = reward).
SELECT 'platform_task_escrow_or_margin' AS problem, id, escrow_account_id, funded_kobo, refunded_kobo, unit_price_kobo, unit_reward_kobo
FROM campaigns WHERE is_platform = 1 AND (escrow_account_id IS NOT NULL OR funded_kobo <> 0 OR refunded_kobo <> 0 OR budget_kobo <> 0 OR unit_price_kobo <> unit_reward_kobo);
SELECT 'platform_task_submission_margin' AS problem, s.id, s.unit_price_kobo, s.unit_reward_kobo
FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE c.is_platform = 1 AND s.unit_price_kobo <> s.unit_reward_kobo;

-- 7. YOLOTASK tasks are owned by the YOLOTASK system account, sit in a bundle on the same platform, and only they have a bundle.
SELECT 'platform_task_owner' AS problem, c.id, c.advertiser_user_id
FROM campaigns c LEFT JOIN settings st ON st.setting_key = 'platform_tasks.owner_user_id'
WHERE c.is_platform = 1 AND CAST(c.advertiser_user_id AS CHAR) <> COALESCE(st.setting_value, '');
SELECT 'platform_task_bundle' AS problem, c.id, c.is_platform, c.bundle_id, c.platform_id, b.platform_id AS bundle_platform_id
FROM campaigns c LEFT JOIN platform_task_bundles b ON b.id = c.bundle_id
WHERE (c.is_platform = 1 AND (b.id IS NULL OR b.platform_id <> c.platform_id)) OR (c.is_platform = 0 AND c.bundle_id IS NOT NULL);

-- 8. One task per bundle + task type + link (the import key).
SELECT 'platform_task_duplicate' AS problem, bundle_id, task_type_id, link_hash, COUNT(*) AS n
FROM campaigns WHERE is_platform = 1 GROUP BY bundle_id, task_type_id, link_hash HAVING COUNT(*) > 1;

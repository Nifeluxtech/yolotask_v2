-- YOLOTASK V2 read-only check: advertiser deposit cashback (migration 0013).
-- Every query returns rows ONLY when something is wrong; an empty result for
-- all of them = healthy. Same rules as Growth\DepositCashback::reconcile
-- (part of Money\Ledger::reconcile, daily cron).
-- Usage: mysql DBNAME < database/verify/deposit_cashback_reconciliation.sql

-- 1. The cashback expense account equals the cashback still standing.
SELECT 'deposit_cashback_account' AS problem, a.balance_kobo, c.paid
FROM ledger_accounts a, (SELECT COALESCE(SUM(amount_kobo), 0) AS paid FROM deposit_cashbacks WHERE status = 'paid') c
WHERE a.code = 'system:deposit_cashback' AND a.balance_kobo <> c.paid;

-- 2. Paid/reversed cashback matches its ledger transactions (key cashback:<orderId>); skipped rows moved no money.
SELECT 'cashback_ledger_link' AS problem, c.id, c.status, c.amount_kobo, t.amount_kobo AS ledger_amount, rt.amount_kobo AS reverse_amount
FROM deposit_cashbacks c
LEFT JOIN ledger_transactions t ON t.id = c.ledger_transaction_id
LEFT JOIN ledger_transactions rt ON rt.id = c.reverse_transaction_id
WHERE (c.status IN ('paid', 'reversed') AND (t.id IS NULL OR t.amount_kobo <> c.amount_kobo OR c.amount_kobo <= 0 OR t.idempotency_key <> CONCAT('cashback:', c.payment_order_id)))
   OR (c.status = 'reversed' AND (rt.id IS NULL OR rt.amount_kobo <> c.amount_kobo))
   OR (c.status = 'skipped' AND (c.ledger_transaction_id IS NOT NULL OR c.amount_kobo <> 0));

-- 3. Cashback only sits on credited AfrStar top-ups, on the credited amount, for the depositor.
SELECT 'cashback_not_on_topup' AS problem, c.id, c.payment_order_id, o.status, c.base_kobo, o.credited_kobo
FROM deposit_cashbacks c LEFT JOIN payment_orders o ON o.id = c.payment_order_id
WHERE o.id IS NULL OR o.status <> 'paid' OR c.base_kobo <> o.credited_kobo OR c.user_id <> o.user_id;

-- 4. Cashback never exceeds 50% of its deposit.
SELECT 'cashback_over_cap' AS problem, c.id, c.base_kobo, c.amount_kobo
FROM deposit_cashbacks c WHERE c.amount_kobo * 2 > c.base_kobo OR c.rate_bps > 5000;

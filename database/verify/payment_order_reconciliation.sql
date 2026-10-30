-- YOLOTASK V2 read-only check: AfrStar top-up orders. Rows = problems.

-- 1. Paid orders must have exactly one credit in the ledger, for the credited amount.
SELECT 'paid_without_ledger' AS problem, o.id, o.order_sn, o.credited_kobo, t.amount_kobo AS ledger_amount
FROM payment_orders o LEFT JOIN ledger_transactions t ON t.id = o.ledger_transaction_id
WHERE o.status = 'paid' AND (t.id IS NULL OR t.amount_kobo <> o.credited_kobo);

-- 2. Orders that are not paid must not have been credited.
SELECT 'credited_but_not_paid' AS problem, o.id, o.order_sn, o.status FROM payment_orders o
WHERE o.status <> 'paid' AND (o.ledger_transaction_id IS NOT NULL OR COALESCE(o.credited_kobo, 0) > 0);

-- 3. A top-up ledger transaction per order at most (idempotency).
SELECT 'duplicate_topup_credit' AS problem, reference_id AS payment_order_id, COUNT(*) AS credits FROM ledger_transactions
WHERE reference_type = 'payment_order' AND type IN ('topup', 'topup_review') GROUP BY reference_id HAVING COUNT(*) > 1;

-- 4. Orders in review must have an open review item.
SELECT 'review_without_item' AS problem, o.id, o.order_sn FROM payment_orders o
WHERE o.status = 'review' AND NOT EXISTS (SELECT 1 FROM payment_reviews r WHERE r.payment_order_id = o.id AND r.status = 'open');

-- 5. Callbacks that were logged but never processed (cron/every-five-minutes.php retries these).
SELECT 'unprocessed_callback' AS problem, id, kind, order_sn, received_at FROM payment_callbacks
WHERE (outcome = 'received' AND processed_at IS NULL AND received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) OR outcome = 'retry_failed';

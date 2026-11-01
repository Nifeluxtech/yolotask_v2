-- YOLOTASK V2 read-only check: earner withdrawal fees and daily check-ins (migration 0014).
-- Every query returns rows ONLY when something is wrong; an empty result for
-- all of them = healthy. Same rules as Money\Fees::reconcileWithdrawals and
-- Growth\Checkin::reconcile (part of Money\Ledger::reconcile, daily cron).
-- Usage: mysql DBNAME < database/verify/withdrawal_fee_checkin_reconciliation.sql

-- 1. The fee revenue account equals the fees of paid withdrawals (rejected/failed ones keep nothing).
SELECT 'withdrawal_fees_account' AS problem, a.balance_kobo, f.fees
FROM ledger_accounts a, (SELECT COALESCE(SUM(fee_kobo), 0) AS fees FROM withdrawals WHERE status = 'paid') f
WHERE a.code = 'system:withdrawal_fees' AND a.balance_kobo <> f.fees;

-- 2. Every stored fee is floor(gross x rate / 10000), the rate is at most 50 % and the net is positive.
SELECT 'withdrawal_fee_math' AS problem, w.id, w.amount_kobo, w.fee_bps, w.fee_kobo
FROM withdrawals w
WHERE w.fee_bps > 5000 OR w.fee_kobo >= w.amount_kobo OR w.fee_kobo <> FLOOR(w.amount_kobo * w.fee_bps / 10000);

-- 3. A paid withdrawal moved the gross out of held, the net through AfrStar clearing and the fee to revenue.
SELECT 'withdrawal_paid_posting' AS problem, w.id, w.amount_kobo, w.fee_kobo, t.amount_kobo AS tx_amount, x.net, x.fee
FROM withdrawals w
LEFT JOIN ledger_transactions t ON t.id = w.final_transaction_id
LEFT JOIN (SELECT e.transaction_id,
                  SUM(CASE WHEN a.code = 'system:afrstar_clearing' AND e.direction = 'credit' THEN e.amount_kobo ELSE 0 END) AS net,
                  SUM(CASE WHEN a.code = 'system:withdrawal_fees' AND e.direction = 'credit' THEN e.amount_kobo ELSE 0 END) AS fee
           FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id GROUP BY e.transaction_id) x ON x.transaction_id = w.final_transaction_id
WHERE w.status = 'paid' AND (t.id IS NULL OR t.idempotency_key <> CONCAT('wd-paid:', w.id) OR t.amount_kobo <> w.amount_kobo
      OR COALESCE(x.net, 0) <> w.amount_kobo - w.fee_kobo OR COALESCE(x.fee, 0) <> w.fee_kobo);

-- 4. A rejected or failed withdrawal returned the full gross amount (fee included) to the wallet.
SELECT 'withdrawal_refund_not_full' AS problem, w.id, w.status, w.amount_kobo, t.amount_kobo AS refunded
FROM withdrawals w LEFT JOIN ledger_transactions t ON t.id = w.final_transaction_id
WHERE w.status IN ('rejected', 'failed') AND (t.id IS NULL OR t.idempotency_key <> CONCAT('wd-release:', w.id) OR t.amount_kobo <> w.amount_kobo);

-- 5. The check-in expense account equals the rewards paid.
SELECT 'checkin_rewards_account' AS problem, a.balance_kobo, c.paid
FROM ledger_accounts a, (SELECT COALESCE(SUM(amount_kobo), 0) AS paid FROM daily_checkins) c
WHERE a.code = 'system:checkin_rewards' AND a.balance_kobo <> c.paid;

-- 6. Every check-in matches its ledger transaction (key checkin:<userId>:<YYYY-MM-DD>), and no check-in posting lacks its row.
SELECT 'checkin_ledger_link' AS problem, c.id, c.user_id, c.lagos_date, c.amount_kobo, t.amount_kobo AS ledger_amount
FROM daily_checkins c LEFT JOIN ledger_transactions t ON t.id = c.ledger_transaction_id
WHERE t.id IS NULL OR t.amount_kobo <> c.amount_kobo OR c.amount_kobo <= 0 OR c.streak < 1
   OR t.idempotency_key <> CONCAT('checkin:', c.user_id, ':', DATE_FORMAT(c.lagos_date, '%Y-%m-%d'));
SELECT 'checkin_orphan_posting' AS problem, t.id, t.idempotency_key
FROM ledger_transactions t LEFT JOIN daily_checkins c ON c.ledger_transaction_id = t.id
WHERE t.type = 'checkin_reward' AND c.id IS NULL;

-- 7. Streaks: day N+1 of a run follows day N for the same earner.
SELECT 'checkin_streak' AS problem, c.id, c.user_id, c.lagos_date, c.streak, p.streak AS previous_streak
FROM daily_checkins c LEFT JOIN daily_checkins p ON p.user_id = c.user_id AND p.lagos_date = c.lagos_date - INTERVAL 1 DAY
WHERE (p.id IS NULL AND c.streak <> 1) OR (p.id IS NOT NULL AND c.streak <> p.streak + 1);

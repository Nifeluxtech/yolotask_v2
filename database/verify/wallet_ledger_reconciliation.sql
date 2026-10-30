-- YOLOTASK V2 read-only check: wallets and ledger. Every query returns rows
-- ONLY when something is wrong; an empty result for all of them = healthy.
-- Same rules as Money\Ledger::reconcile (run by cron/daily.php).
-- Usage: mysql DBNAME < database/verify/wallet_ledger_reconciliation.sql

-- 1. Total debits must equal total credits.
SELECT 'total_unbalanced' AS problem, SUM(CASE WHEN direction = 'debit' THEN amount_kobo ELSE 0 END) AS debits, SUM(CASE WHEN direction = 'credit' THEN amount_kobo ELSE 0 END) AS credits
FROM ledger_entries HAVING debits <> credits;

-- 2. Every transaction balanced with at least two entries.
SELECT 'transaction_unbalanced' AS problem, transaction_id, SUM(CASE WHEN direction = 'debit' THEN CAST(amount_kobo AS SIGNED) ELSE -CAST(amount_kobo AS SIGNED) END) AS diff, COUNT(*) AS entries
FROM ledger_entries GROUP BY transaction_id HAVING diff <> 0 OR entries < 2;

-- 3. Cached account balances equal the sum of their entries.
SELECT 'account_cache' AS problem, a.id, a.code, a.balance_kobo,
       COALESCE(SUM(CASE WHEN e.direction = a.normal_side THEN CAST(e.amount_kobo AS SIGNED) ELSE -CAST(e.amount_kobo AS SIGNED) END), 0) AS computed
FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id = a.id
GROUP BY a.id, a.code, a.balance_kobo, a.normal_side HAVING computed <> a.balance_kobo;

-- 4. Wallet caches equal their ledger accounts and are never negative.
SELECT 'wallet_cache' AS problem, w.id, w.available_kobo, av.balance_kobo AS available_account, w.held_kobo, hd.balance_kobo AS held_account
FROM wallets w
LEFT JOIN ledger_accounts av ON av.wallet_id = w.id AND av.kind = 'wallet_available'
LEFT JOIN ledger_accounts hd ON hd.wallet_id = w.id AND hd.kind = 'wallet_held'
WHERE av.id IS NULL OR hd.id IS NULL OR w.available_kobo <> av.balance_kobo OR w.held_kobo <> hd.balance_kobo OR w.available_kobo < 0 OR w.held_kobo < 0;

-- 5. Money on hold equals open withdrawals.
SELECT 'held_vs_withdrawals' AS problem, h.held, o.open_withdrawals
FROM (SELECT COALESCE(SUM(held_kobo), 0) AS held FROM wallets) h,
     (SELECT COALESCE(SUM(amount_kobo), 0) AS open_withdrawals FROM withdrawals WHERE status IN ('pending_review', 'processing')) o
WHERE h.held <> o.open_withdrawals;

-- 6. Withdrawals: finished ones have a final ledger transaction, open ones a hold.
SELECT 'withdrawal_ledger_link' AS problem, id, status, hold_transaction_id, final_transaction_id FROM withdrawals
WHERE hold_transaction_id IS NULL OR (status IN ('paid', 'failed', 'rejected') AND final_transaction_id IS NULL);

-- YOLOTASK V2 read-only check: referral payouts and bonuses (Phase 6,
-- migration 0009). Every query returns rows ONLY when something is wrong; an
-- empty result for all of them = healthy. Same rules as
-- Growth\Referrals::reconcile (part of Money\Ledger::reconcile, daily cron).
-- Usage: mysql DBNAME < database/verify/referral_award_reconciliation.sql

-- 1. The bonus expense account equals the milestone and activation payouts still standing (activation: migration 0020).
SELECT 'referral_bonus_account' AS problem, a.balance_kobo, p.paid
FROM ledger_accounts a, (SELECT COALESCE(SUM(amount_kobo), 0) AS paid FROM referral_payouts WHERE kind IN ('milestone', 'activation') AND status = 'paid') p
WHERE a.code = 'system:referral_bonus' AND a.balance_kobo <> p.paid;

-- 2. The commission expense account equals the commission payouts still standing.
SELECT 'referral_commission_account' AS problem, a.balance_kobo, p.paid
FROM ledger_accounts a, (SELECT COALESCE(SUM(amount_kobo), 0) AS paid FROM referral_payouts WHERE kind = 'commission' AND status = 'paid') p
WHERE a.code = 'system:referral_commission' AND a.balance_kobo <> p.paid;

-- 3. Paid/reversed payouts match their ledger transactions; skipped ones moved no money.
SELECT 'payout_ledger_link' AS problem, p.id, p.status, p.amount_kobo, t.amount_kobo AS ledger_amount, rt.amount_kobo AS reverse_amount
FROM referral_payouts p
LEFT JOIN ledger_transactions t ON t.id = p.ledger_transaction_id
LEFT JOIN ledger_transactions rt ON rt.id = p.reverse_transaction_id
WHERE (p.status IN ('paid', 'reversed') AND (t.id IS NULL OR t.amount_kobo <> p.amount_kobo OR p.amount_kobo <= 0))
   OR (p.status = 'reversed' AND (rt.id IS NULL OR rt.amount_kobo <> p.amount_kobo))
   OR (p.status = 'skipped' AND (p.ledger_transaction_id IS NOT NULL OR p.amount_kobo <> 0));

-- 4. Commission is only paid on credited AfrStar top-ups, on the credited amount.
SELECT 'commission_not_on_topup' AS problem, p.id, p.payment_order_id, o.status, p.base_kobo, o.credited_kobo
FROM referral_payouts p LEFT JOIN payment_orders o ON o.id = p.payment_order_id
WHERE p.kind = 'commission' AND (o.id IS NULL OR o.status <> 'paid' OR p.base_kobo <> o.credited_kobo);

-- 5. Commission amount = floor(base x rate / 10000).
SELECT 'commission_amount' AS problem, id, base_kobo, rate_bps, amount_kobo FROM referral_payouts
WHERE kind = 'commission' AND amount_kobo <> FLOOR(base_kobo * rate_bps / 10000);

-- 6. A milestone is paid at most once per referral (unique key) and only to the referral's referrer.
SELECT 'payout_wrong_referrer' AS problem, p.id, p.referrer_user_id, r.referrer_user_id AS expected
FROM referral_payouts p JOIN referrals r ON r.id = p.referral_id
WHERE p.referrer_user_id <> r.referrer_user_id OR p.referee_user_id <> r.referee_user_id;

-- 7. No money to flagged or rejected referrals (payouts made before a later rejection are listed for review).
SELECT 'payout_on_unapproved_referral' AS problem, p.id, r.status FROM referral_payouts p JOIN referrals r ON r.id = p.referral_id
WHERE p.status = 'paid' AND r.status <> 'active';

-- 8. No self-referral.
SELECT 'self_referral' AS problem, id FROM referrals WHERE referrer_user_id = referee_user_id;

-- 9. Milestone payouts reflect real approved work (informational: approvals reversed after payout show here; review in Admin -> Referrals).
SELECT 'milestone_above_current_approvals' AS problem, p.id, p.milestone_task_count,
       (SELECT COUNT(*) FROM submissions s WHERE s.earner_user_id = p.referee_user_id AND s.status = 'approved' AND s.decided_at >= r.created_at) AS approved_now
FROM referral_payouts p JOIN referrals r ON r.id = p.referral_id
WHERE p.kind = 'milestone' AND p.status = 'paid'
  AND p.milestone_task_count > (SELECT COUNT(*) FROM submissions s WHERE s.earner_user_id = p.referee_user_id AND s.status = 'approved' AND s.decided_at >= r.created_at);

-- 10. Admin bonuses: every bonus transaction is balanced against system:bonuses and a wallet.
SELECT 'bonus_shape' AS problem, t.id FROM ledger_transactions t
WHERE t.type = 'bonus' AND (SELECT COUNT(*) FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id
    WHERE e.transaction_id = t.id AND ((a.code = 'system:bonuses' AND e.direction = 'debit') OR (a.kind = 'wallet_available' AND e.direction = 'credit'))) <> 2;

-- 11. Every activation bonus belongs to a referee whose account is activated (migration 0020).
SELECT 'activation_bonus_without_activation' AS problem, p.id FROM referral_payouts p
LEFT JOIN account_activations aa ON aa.user_id = p.referee_user_id
WHERE p.kind = 'activation' AND aa.user_id IS NULL;

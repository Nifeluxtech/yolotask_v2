-- YOLOTASK V2 read-only check: campaign escrow (Phase 4). Rows = problems.
-- Same rules as Money\Ledger::reconcileCampaigns (cron/hourly.php, cron/daily.php).

-- 1. Each campaign's escrow account equals its unspent budget.
SELECT 'escrow_vs_unspent' AS problem, c.id, c.funded_kobo - c.spent_kobo - c.refunded_kobo AS unspent, COALESCE(a.balance_kobo, 0) AS escrow
FROM campaigns c LEFT JOIN ledger_accounts a ON a.id = c.escrow_account_id
WHERE COALESCE(a.balance_kobo, 0) <> c.funded_kobo - c.spent_kobo - c.refunded_kobo OR c.funded_kobo - c.spent_kobo - c.refunded_kobo < 0;

-- 2. Total escrow equals total unspent budgets.
SELECT 'escrow_total' AS problem, e.escrow, u.unspent
FROM (SELECT COALESCE(SUM(balance_kobo), 0) AS escrow FROM ledger_accounts WHERE kind = 'campaign_escrow') e,
     (SELECT COALESCE(SUM(funded_kobo - spent_kobo - refunded_kobo), 0) AS unspent FROM campaigns) u
WHERE e.escrow <> u.unspent;

-- 3. Funded campaigns were funded with exactly their budget, once, plus any
--    Phase 5 appeal top-ups (appeals overturned after the budget was used).
SELECT 'funding' AS problem, c.id, c.status, c.budget_kobo, c.funded_kobo,
       (SELECT COALESCE(SUM(amount_kobo), 0) FROM campaign_funding_events f WHERE f.campaign_id = c.id AND f.event = 'lock') AS locked,
       (SELECT COALESCE(SUM(amount_kobo), 0) FROM campaign_funding_events f WHERE f.campaign_id = c.id AND f.event = 'appeal_topup') AS appeal_topups
FROM campaigns c
WHERE (c.status <> 'draft' AND c.funded_kobo <> c.budget_kobo + (SELECT COALESCE(SUM(amount_kobo), 0) FROM campaign_funding_events f WHERE f.campaign_id = c.id AND f.event = 'appeal_topup'))
   OR (c.status = 'draft' AND c.funded_kobo <> 0)
   OR c.funded_kobo <> (SELECT COALESCE(SUM(amount_kobo), 0) FROM campaign_funding_events f WHERE f.campaign_id = c.id AND f.event IN ('lock', 'appeal_topup'));

-- 4. Refunds recorded equal refunded_kobo.
SELECT 'refunds' AS problem, c.id, c.refunded_kobo, COALESCE(SUM(f.amount_kobo), 0) AS refund_events
FROM campaigns c LEFT JOIN campaign_funding_events f ON f.campaign_id = c.id AND f.event = 'refund'
GROUP BY c.id, c.refunded_kobo HAVING refund_events <> c.refunded_kobo;

-- 5. Spent and counters match submissions.
SELECT 'counters' AS problem, c.id, c.spent_kobo, c.approved_count, c.pending_count,
       COALESCE(SUM(CASE WHEN s.status = 'approved' THEN s.unit_price_kobo END), 0) AS approved_value,
       COALESCE(SUM(s.status = 'approved'), 0) AS approved_n, COALESCE(SUM(s.status = 'pending'), 0) AS pending_n
FROM campaigns c LEFT JOIN submissions s ON s.campaign_id = c.id
GROUP BY c.id, c.spent_kobo, c.approved_count, c.pending_count
HAVING approved_value <> c.spent_kobo OR approved_n <> c.approved_count OR pending_n <> c.pending_count;

-- 6. Closed campaigns keep only the price of pending submissions.
SELECT 'closed_escrow' AS problem, id, status, funded_kobo - spent_kobo - refunded_kobo AS unspent, pending_count * unit_price_kobo AS needed
FROM campaigns WHERE status IN ('completed', 'cancelled', 'ended', 'rejected') AND funded_kobo - spent_kobo - refunded_kobo <> pending_count * unit_price_kobo;

-- 7. Never more taken slots than quantity, except approvals paid on appeal
--    over the budget (Phase 5), which are recorded in extra_approved_count.
SELECT 'overfilled' AS problem, id, quantity, extra_approved_count, approved_count, pending_count FROM campaigns WHERE approved_count + pending_count > quantity + extra_approved_count;

-- 7b. Every over-budget approval is an overturned appeal marked over_budget.
SELECT 'over_budget_unrecorded' AS problem, c.id, c.extra_approved_count,
       (SELECT COUNT(*) FROM submission_appeals a WHERE a.campaign_id = c.id AND a.status = 'overturned' AND a.over_budget = 1) AS over_budget_appeals
FROM campaigns c
WHERE c.extra_approved_count <> (SELECT COUNT(*) FROM submission_appeals a WHERE a.campaign_id = c.id AND a.status = 'overturned' AND a.over_budget = 1);

-- 8. Revenue = margins of approved tasks; reward hold = unreleased rewards.
SELECT 'revenue' AS problem, a.balance_kobo, m.margins
FROM ledger_accounts a, (SELECT COALESCE(SUM(unit_price_kobo - unit_reward_kobo), 0) AS margins FROM submissions WHERE status = 'approved') m
WHERE a.code = 'system:revenue' AND a.balance_kobo <> m.margins;
SELECT 'reward_hold' AS problem, a.balance_kobo, h.held
FROM ledger_accounts a, (SELECT COALESCE(SUM(unit_reward_kobo), 0) AS held FROM submissions WHERE reward_status = 'held') h
WHERE a.code = 'system:reward_hold' AND a.balance_kobo <> h.held;

-- Phase 5: the platform's appeal receivable equals open advertiser debts.
SELECT 'advertiser_receivable' AS problem, a.balance_kobo, d.open_debts
FROM ledger_accounts a, (SELECT COALESCE(SUM(amount_kobo), 0) AS open_debts FROM advertiser_debts WHERE status = 'open') d
WHERE a.code = 'system:advertiser_receivable' AND a.balance_kobo <> d.open_debts;

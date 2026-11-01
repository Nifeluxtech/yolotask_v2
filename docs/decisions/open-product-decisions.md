# Open product decisions — defaults chosen

Each default below is an admin-editable setting (Admin → Tasks → Task
settings, or Money settings) until the product owner decides otherwise.

| Decision | Default chosen (Phase 4/5) | Setting |
|---|---|---|
| Stale-submission limit | Pending > 24 h is "stale" (admin list); auto-approve resolves it | `tasks.stale_hours` |
| Reward hold window | 0 h (paid on approval) | `tasks.reward_hold_hours` |
| Resubmission cap | 1 resubmission after a rejection | `tasks.resubmission_cap` |
| Auto-approve window | 48 h default; advertisers pick 12–336 h | `tasks.auto_approve_*_hours` |
| Slot hold after starting | 60 min | `tasks.reservation_minutes` |
| Tasks an earner may hold at once | 3 | `tasks.max_active_reservations` |
| Proof file size | 2 MB | `tasks.proof_max_bytes` |
| Campaign quantity | 1–100,000 | `campaigns.min_quantity`, `campaigns.max_quantity` |
| Campaign approval | Every campaign is approved by an admin before going live | — |
| Targeting | Premium ("targeted") = premium price + reward + first in feed + interest match: shown/reservable only when the earner shares ≥ 1 active interest with the campaign and their level allows premium. Earners with no interests see no premium tasks. Campaigns with no active interest (legacy, or all disabled) match every earner who has chosen interests (Hark reading of "match all") | Admin → Growth → Interests |
| Interests | Earners pick 1–3; every new campaign picks 1–3 (required; editable by the advertiser while draft/pending, by admins while pending/live/paused). 12 seeded; admin adds/renames/reorders/disables, never deletes; at least 3 stay active | — |
| Same target twice | One open campaign per normalised link + task type; earners never see a repeat of a target they did | — |
| Path after a rejection | Earner may resubmit (cap above) **or** appeal once — never both | — |
| Appeal window | 72 h after the rejection (approved by the product owner) | `appeals.window_hours` |
| Appeal after a resubmission | Not allowed; an appealed proof cannot be resubmitted | — |
| Who can be appealed | Rejections by the advertiser, an admin or a reviewer (stale queue); appeal decisions are final. The reviewer who rejected cannot handle the appeal (Hark default) | — |
| Reviewer claim lock | 30 min, exclusive; expired claims return to the queue (5-minute cron) | `reviews.claim_minutes` |
| Admin override | Admins decide open appeals or override a reviewer once; final and audited. Overriding an overturn reverses the payment, refused if the earner no longer has the reward (Hark default) | — |
| Overturn after the budget is used | Escrow if it has room beyond pending proofs and other earners' slot holds; else advertiser wallet; else the platform fronts it from `system:advertiser_receivable` and an advertiser debt is opened for admins. Recorded as "over budget" (never silent), never takes another earner's slot. Receivable rather than `system:revenue` so revenue keeps equalling task margins (Hark choice) | — |
| Advertiser debts | Admin collects from the wallet or writes off; no automatic deduction from future top-ups yet | — |
| Advertiser quality flag | ≥ 3 overturned **and** ≥ 30 % of decided appeals against the advertiser's own rejections, last 30 days; admin-only, no automatic penalty. Rate = overturned ÷ decided appeals (Hark interpretation) | `appeals.flag_min_overturns`, `appeals.flag_rate_percent`, `appeals.flag_window_days` |
| Stale-proof reviews | Reviewers may approve/reject stale proofs before auto-approve (same money path, reviewer recorded) | `tasks.stale_hours` |
| Reviewer pay | Later phase; every reviewer decision is logged (`reviewer_actions`) | — |
| Referral bonus for referred earners | Milestones on the referee's approved tasks after the referral: 10 = ₦100, 25 = ₦200 (approved by the product owner), stacking, once per referee; admin adds/edits/disables unlimited milestones; a milestone that already paid can be disabled, not deleted | Admin → Money → Referrals |
| Referral commission for referred advertisers | 5 % of every confirmed AfrStar deposit for life (approved); admin 0–50 % in basis points; reviewed AfrStar payments count, admin credits/bonuses never | `referrals.commission_bps` |
| Advertiser deposit cashback | Admin sets a percentage (0–50 %, basis points, default 0 = off) and a minimum deposit (default 0 = none; below it no cashback — confirmed by the product owner, Nov 2026); paid on the credited amount of every confirmed AfrStar deposit, rounded down to the kobo, into the advertiser's wallet; admin credits/bonuses never earn it and it never earns commission. Hark choices: the rate in force when the deposit is confirmed applies; switching on never pays past deposits (catch-up looks back 48 h only); accounts not active at confirmation get none; in-app notification only (no email); admin can reverse a paid cashback like a referral payout (no deposit reversal exists) | `cashback.rate_bps`, `cashback.min_deposit_kobo` (Admin → Growth → Referrals) |
| Referral abuse | Not recorded: self-referral by normalised email (Gmail dots, `+tag`) or an alias of any existing account. Allowance per referrer (approved by the product owner, Nov 2026): up to `referrals.ip_allowance` (default 3) referred sign-ups from one IP address are accepted and paid as usual — the referrer's own IP counts as that IP (it gets the same allowance, it does not use a slot); the 4th and later from that IP are flagged (nothing paid until an admin approves). The same allowance applies per device (browser user-agent hash, the only device signal a web app has; common phone models can share one). A sign-up on the referrer's own device — same device AND network as one of the referrer's sessions in the last 30 days — is always flagged as a likely self-referral. Earlier referees count whatever their status, for all time. Changing the allowance never re-checks earlier sign-ups | Admin → Growth → Referrals (`referrals.ip_allowance`, 1–100) |
| Reversed approval after a milestone paid | No automatic claw-back; the payout is flagged and an admin may reverse it (ledger reversal, reason required; refused if the referrer already spent it) | — |
| Referral programme switched off | No new referrals recorded and nothing paid; when switched back on the hourly catch-up pays what was earned meanwhile (Hark choice) | `referrals.enabled` |
| Earner levels | Starter 0, Bronze 50 / 80 %, Silver 200 / 85 %, Gold 500 / 90 %; hold 3/4/5/6 tasks; premium feed on for every level by default so premium campaigns are not starved at launch (Hark choice; switch off per level) | Admin → Tasks → Earner levels, `levels.enabled` |
| Notification emails | Every category emails by default except task approvals, which stay in the inbox (one email per approved task would be noise; Hark choice). Announcements are inbox + banner only. Security emails are always sent | per user, `notifications.php` |
| Notification retention | Read notifications deleted after 90 days; unread kept | `notifications.retention_days` |
| Admin bonus | Separate from wallet adjustments: expense account `system:bonuses`, reason required, user notified | — |
| Leaderboard | V1 port: Top Workers (approved tasks by approval time + rewards) and Top Referrers (paid referral milestones + deposit commissions, reversed excluded), this week (Mon 00:00 WAT) / this month (1st 00:00 WAT), top 50; first name + last initial for earners; staff, suspended and closed accounts excluded; advertisers can rank as referrers; ties to whoever got there first (Hark readings) | — |
| Leadership rewards | Manual, from Admin → Growth → Leaderboard: ₦1–₦100,000, note ≤ 140 characters, paid as an admin bonus (`system:bonuses`), notified "Leadership bonus"; no automatic period-end payouts (`cron/close-leaderboard-period.php` stays a placeholder) | — |
| Virtual numbers | Later phase | — |

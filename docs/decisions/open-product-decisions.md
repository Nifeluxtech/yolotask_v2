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
| Targeting | No user gender/interest data yet: "targeted" = premium price + reward + first in feed | — |
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
| Referral, levels, virtual numbers | Later phases | — |

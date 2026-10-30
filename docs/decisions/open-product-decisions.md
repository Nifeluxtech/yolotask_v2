# Open product decisions — defaults chosen

Each default below is an admin-editable setting (Admin → Tasks → Task
settings, or Money settings) until the product owner decides otherwise.

| Decision | Default chosen (Phase 4) | Setting |
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
| Path after advertiser upholds a rejection | Phase 5 (appeals) | — |
| Reviewer compensation, referral, levels, virtual numbers | Later phases | — |

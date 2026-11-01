# Cron and operations

All jobs use `/opt/cpanel/ea-php84/root/usr/bin/php` on staging (see each
file's header for the exact cPanel line).

| File | When | What |
|---|---|---|
| `cron/every-five-minutes.php` | `*/5` | Retry unfinished AfrStar callbacks, expire stale top-ups, flag stuck payouts, release expired reviewer claims, deliver started announcements to inboxes (Phase 6). |
| `cron/every-fifteen-minutes.php` | `*/15` | Expire task reservations, auto-approve due submissions (pays referral milestones too), end campaigns past their end date, stale and appeal counts. |
| `cron/hourly.php` | `5 * * * *` | Release held rewards, referral catch-up sweep (milestones and deposit commissions missed by the immediate path, Phase 6), deposit cashback catch-up (deposits confirmed in the last 48 h with no cashback decision), campaign escrow reconciliation. |
| `cron/daily.php` | `15 2 * * *` | Session/code/throttle cleanup, delete read notifications older than `notifications.retention_days` (90), expire top-ups, full ledger reconciliation incl. referral payouts (exit 2 on mismatch). |

Phase 6 adds no new cron lines. Optional web push: copy
`config/notifications.example.php` to `config-private/notifications.php`
and fill in the OneSignal keys; without the file push is off.

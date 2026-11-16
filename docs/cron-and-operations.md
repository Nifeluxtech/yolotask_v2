# Cron and operations

All jobs use `/opt/cpanel/ea-php84/root/usr/bin/php` on staging (see each
file's header for the exact cPanel line).

| File | When | What |
|---|---|---|
| `cron/every-five-minutes.php` | `*/5` | Retry unfinished AfrStar callbacks, expire stale top-ups and account activation payments (0019), flag stuck payouts, release expired reviewer claims, deliver started announcements to inboxes (Phase 6). |
| `cron/every-fifteen-minutes.php` | `*/15` | Expire task reservations, auto-approve due submissions (pays referral milestones too), end campaigns past their end date, stale and appeal counts. |
| `cron/hourly.php` | `5 * * * *` | Release held rewards, referral catch-up sweep (milestones and deposit commissions missed by the immediate path, Phase 6), deposit cashback catch-up (deposits confirmed in the last 48 h with no cashback decision), campaign escrow reconciliation. |
| `cron/daily.php` | `15 2 * * *` | Session/code/throttle cleanup, delete read notifications older than `notifications.retention_days` (90), expire top-ups, full ledger reconciliation incl. referral payouts (exit 2 on mismatch). |

Phase 6 adds no new cron lines. Optional web push: copy
`config/notifications.example.php` to `config-private/notifications.php`
and fill in the OneSignal keys; without the file push is off.

## Maintenance mode

Admin portal → Tasks → **Maintenance** (`https://admin.yolotask.com.ng/maintenance.php`, also a button on the admin dashboard).

- **Switch ON** before a risky deploy, a database change or an incident: every request to the main site and the earner, advertiser and reviewer portals (pages, sign-in, registration, form POSTs) gets the maintenance page with HTTP 503, `Retry-After` and no-cache headers. Requests sent by `fetch`/XHR or asking for JSON get `{"ok":false,"error":"maintenance","message":…,"until":…,"retry_after":…}` with 503. Users stay signed in; they see the page until it is switched off.
- **Message** (up to 255 characters; empty = "YOLOTASK is getting an upgrade. We'll be back shortly.") and an optional **Back by** time in Lagos time (within 30 days). The page shows "We expect to be back by …"; `Retry-After` counts down to it (600 s when no time is set or it has passed, when the page says it is taking longer than planned).
- **Never paused:** the whole admin portal (a warning banner shows on every admin page while ON), payment callbacks under `/hooks/` (AfrStar, Korapay), cron and CLI scripts, static files (CSS, JS, images, `manifest.json`, `service-worker.js`), and any front controller that defines `YOLOTASK_MAINTENANCE_EXEMPT` before `shared/bootstrap.php` (use this for a health check). Cron keeps expiring task reservations and auto-approving submissions while the site is closed, so keep maintenance windows short.
- Settings `maintenance.enabled` (`0`/`1`), `maintenance.message`, `maintenance.until` (`Y-m-d H:i`, Africa/Lagos). No migration: missing rows mean OFF. If the settings cannot be read (database down) the site stays up (fails open) and logs `maintenance.lookup_failed`. Switching is audited (`admin.maintenance_enabled`, `admin.maintenance_disabled`, `admin.maintenance_updated`).
- **From the command line** (cPanel Terminal, from the current release; also usable as a one-shot cron to open or close at a set time): `php scripts/set-setting.php --key=maintenance.enabled --value=0 --config-dir=$HOME/yolotask-v2-live/config-private` (`--value=1` to switch on; same for `maintenance.message` and `maintenance.until`). Last resort in phpMyAdmin: `UPDATE settings SET setting_value = '0' WHERE setting_key = 'maintenance.enabled';`.
- Test: `php tests/smoke/maintenance-mode.php` (php CLI only; starts the built-in server with a stub settings source).

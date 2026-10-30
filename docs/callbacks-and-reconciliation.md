# Callbacks and reconciliation

## AfrStar callbacks
- Endpoints: `/hooks/afrstar/collection.php`, `/hooks/afrstar/payout.php`
  (main site; see hooks/README.md). No session or CSRF; authenticity is the
  MD5 signature, merchant number, order number, platform order number and the
  stored amount. Optional source-IP allowlist (`callback_ips`).
- Every callback is stored raw in `payment_callbacks` before anything else.
  HTTP 200 with the exact body `SUCCESS` is sent only after the result is
  committed; anything else gets a non-200 so AfrStar retries.
- Replays are no-ops (unique ledger idempotency keys + state checks under
  row locks). Mismatched amounts, unknown orders and contradictory payout
  results go to `payment_reviews`; nothing there is credited automatically.
- `cron/every-five-minutes.php` re-runs stored callbacks that never finished
  processing (crash or database outage) through the same checks, and flags
  payouts with no callback after `money.payout_stuck_hours`.
- AfrStar has no status-query endpoint: callbacks are the source of truth.

## Reconciliation
- `Money\Ledger::reconcile()` (cron/daily.php, admin "Run reconciliation"):
  ledger balanced, account caches = entries, wallet caches = accounts, held =
  open withdrawals, and the campaign checks (escrow = unspent budgets,
  counters = submissions, closed campaigns hold only pending work, revenue =
  margins, reward hold = unreleased rewards). `cron/hourly.php` runs the
  campaign part every hour. Exit code 2 = mismatch; details in the cron log.
- The same rules as plain SQL, for an operator with database access:
  `database/verify/wallet_ledger_reconciliation.sql`,
  `payment_order_reconciliation.sql`, `campaign_funding_reconciliation.sql`,
  `pre_release_checks.sql`. Each query returns rows only when something is
  wrong. Run them before and after any migration or release.

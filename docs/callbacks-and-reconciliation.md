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

## AfrStar callback hub (shared merchant: SPLUG and later apps)
AfrStar allows only **one collection and one payout callback URL per
merchant**. SPLUG uses the same merchant as YOLOTASK, so YOLOTASK's hooks act
as the hub and relay SPLUG's callbacks to it.

- Config: `config-private/afrstar-forward.php` (copy of
  `config/afrstar-forward.example.php`, mode 0600). **No file = forwarding
  off**; every callback is then handled as YOLOTASK's, exactly as before.
- Each route is `['name' => 'splug', 'pattern' => '/^(DEP|WDL)-/', 'url' =>
  'https://splug-theta.vercel.app/api/gateway', 'timeout' => 10]`. Routes are
  tried in order; first match wins. Add more routes (e.g. V1 `/^TOP-/`) the
  same way. URLs must be https on a public host (no localhost, no private or
  loopback IPs, no credentials in the URL); invalid routes are ignored and
  logged (`payments.forward_route_rejected`). The host is resolved at send
  time, private addresses are refused, and the address is pinned for the
  request; no proxy, no redirects followed.
- Flow in both hooks, before any YOLOTASK lookup: POST only (405 otherwise),
  body over 64 KB → 413 (applies to every callback), read `order_sn` (JSON or
  form-encoded). YOLOTASK refs (`YTP…`, `YTW…`) are **never** forwarded. A
  matching ref gets the exact raw body POSTed with the original
  `Content-Type` (default `application/json`) to the route URL. Downstream
  2xx/4xx status is relayed with a short one-line body (`success` passes
  through); timeout, connection error, redirect or 5xx → **502** so AfrStar
  retries. YOLOTASK does not check the signature of forwarded callbacks (SPLUG
  verifies with its own keys) and never touches the ledger, orders,
  withdrawals, `payment_callbacks` or `payment_reviews` for them.
- Log: table `afrstar_callback_forwards` (migration 0007): route, kind,
  order_sn, source IP, content type, body size + SHA-256 (no body, no
  signature), downstream status, latency, outcome
  (`delivered`/`rejected`/`failed`/`timeout`). Kept 180 days (cron/daily.php).
  Because forwarded callbacks never enter `payment_callbacks`, the five-minute
  retry, stuck-payout checks and reconciliation (cron and `database/verify/`)
  never see them. Admin → Payments → "Forwarded callbacks" lists the latest 20
  (read-only).
- Non-matching unknown orders keep the old behaviour (404, logged, unmatched
  review when the merchant number matches).

### Switching SPLUG onto the hub
1. Deploy, run `scripts/migrate.php` (applies 0007), create
   `config-private/afrstar-forward.php` with the SPLUG route.
2. In the AfrStar merchant portal the callback URLs are YOLOTASK's:
   collection `https://staging.yolotask.com.ng/hooks/afrstar/collection.php`,
   payout `https://staging.yolotask.com.ng/hooks/afrstar/payout.php`
   (main domain once V2 is live). **SPLUG's own `/api/gateway` URL is no
   longer entered in the portal**; SPLUG keeps receiving the same JSON,
   relayed by YOLOTASK.
3. Check Admin → Payments → Forwarded callbacks after a small SPLUG deposit.
   If YOLOTASK is down, AfrStar retries; nothing is lost but SPLUG waits.

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

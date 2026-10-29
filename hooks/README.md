# AfrStar callback hooks

AfrStar v2 (docs/providers/afrstar-v2.md) confirms top-ups and payouts only by
server-to-server callbacks. These files handle them.

| File | Purpose |
|---|---|
| `afrstar_callback.php` | Shared runner: bootstraps without a session, reads the raw body (JSON, form-encoded tolerated), calls `YoloTask\Payments\CallbackProcessor`, answers, then sends emails. |
| `afrstar_collection.php` | Collection (top-up) callback. |
| `afrstar_payout.php` | Payout (withdrawal) callback. |

They live outside every document root. The public entry points are two
three-line files in the main site's document root that include them:

- `apps/main/public/hooks/afrstar/collection.php`
- `apps/main/public/hooks/afrstar/payout.php`

## URLs to set in the AfrStar merchant portal

Staging (main staging host → `~/yolotask-v2-live/current/apps/main/public`):

- Collection callback URL: `https://staging.yolotask.com.ng/hooks/afrstar/collection.php`
- Payout callback URL: `https://staging.yolotask.com.ng/hooks/afrstar/payout.php`

Production (once the apex domain serves `apps/main/public`):

- `https://yolotask.com.ng/hooks/afrstar/collection.php`
- `https://yolotask.com.ng/hooks/afrstar/payout.php`

The top-up `redirect_url` is generated per order:
`https://advertiser-staging.yolotask.com.ng/topup-return.php?o=<order_sn>` on
staging (from `yolotask_portal_url('advertiser')`). The return page never
credits anything; it only shows the order status and refreshes.

AfrStar allows one collection and one payout callback URL per merchant. If
another system (for example V1) uses the same merchant account, changing these
URLs moves its callbacks here too.

## Behaviour

- POST only (GET answers 405). No session, no CSRF: authenticity is the
  AfrStar signature, checked in constant time.
- Every request is stored raw in `payment_callbacks` before anything else.
- Collection: `status` 1 = paid. Signature
  `md5(ak + platform_osn + status + ORIGINAL stored order amount + sk)`;
  merchant_sn, order_sn and (when stored) platform_osn must match. If
  `amount_paid` equals the order amount the wallet is credited once (ledger
  key `payin:<order_sn>`); otherwise the order goes to the admin payment
  review queue and is never auto-credited. Other statuses are recorded only.
- Payout: `status` 1 = paid (held → paid out), 9 = failed (held → available,
  earner emailed). Signature `md5(ak + platform_osn + status + amount + sk)`
  with the stored payout amount; the callback amount must equal it.
- The body is exactly `SUCCESS` with HTTP 200, only after the result is
  committed. Unknown orders (404), bad signatures or mismatched references
  (400), missing credentials (503) and errors (500) get a non-200 so AfrStar
  retries. Replays are no-ops that still answer `SUCCESS`.
- Emails are sent after the response (`fastcgi_finish_request`) to stay well
  inside AfrStar's five-second timeout.
- Optional source-IP allowlist: `afrstar.callback_ips` in
  `config-private/payments.local.php`.

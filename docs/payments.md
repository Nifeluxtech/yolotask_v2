# Payments (Phase 3)

Provider: AfrStar Nigeria API v2 (`docs/providers/afrstar-v2.md`). Client:
`shared/lib/Payments/AfrstarClient.php`. Callbacks: `hooks/README.md`.

## Credentials

Never in Git. The private config directory (`~/yolotask-v2-live/config-private`)
holds `afrstar.key`, one line: `MERCHANT_SN,MERCHANT_AK,MERCHANT_SK` (values
trimmed), mode 0600. Optionally copy `config/payments.example.php` there as
`payments.local.php` (it reads `afrstar.key` and sets the base URL, timeout,
callback IP allowlist and optional Paystack name lookup). Without credentials
the top-up and withdraw pages say "Payments are not available yet" and the
callbacks answer 503.

## Flows

- **Top-up (advertisers).** `topup.php` creates a `payment_orders` row
  (`YTP…` order number, amount in kobo), calls `/gateway/payin/` and redirects
  to `pay_url`. The return page only shows status. The collection callback
  credits the wallet; mismatched amounts go to review.
- **Account activation (earners, migration 0019; only while `activation.required` is on).** `activate.php` creates an
  `activation_payments` row (`YTA…` order number), calls `/gateway/payin/` and
  redirects to `pay_url`. The same collection callback confirms it: the fee is
  platform revenue (Dr `system:afrstar_clearing` / Cr
  `system:activation_revenue`), never a wallet credit, and activates the
  account. Mismatches go to the same review queue; admins can verify a payment
  manually. See `docs/platform-tasks.md`.
- **Withdrawal (earners).** One saved bank account (bank from the AfrStar
  code list, 10-digit NUBAN, typed account name; changing it needs the
  password and emails an alert). A request moves the amount from available to
  held (`pending_review`); one open withdrawal per earner. A withdrawal fee
  (below) is previewed first and taken from the amount at payout. Admin approves
  (status `processing` is committed first, then `/gateway/payout/` with order
  number `YTW<id>A<attempt>`) or rejects with a reason (hold released). A
  definitive AfrStar refusal returns it to review; an unclear outcome stays
  processing until the callback. Payout callback 1 = paid, 9 = refunded.
- **Admin.** Money → Wallets (totals, live AfrStar balance, reconciliation,
  user search, manual credit/debit with mandatory reason), Withdrawals,
  Payments & review (review queue, orders, raw callback log), Ledger, Money
  settings (min/max top-up and withdrawal, earner withdrawal fee, unpaid-order expiry).

## Hardening added with Phase 4

- **Bank details encrypted at rest.** `bank_accounts.account_number` and
  `withdrawals.account_number` hold `enc2:` + base64(IV‖tag‖ciphertext),
  OpenSSL AES-256-GCM (random 12-byte IV, 16-byte tag, the prefix as AAD) with
  a key derived by HKDF-SHA256 from `security_key` under its own context
  (`yolotask-sensitive-fields-v2-aes-256-gcm`, distinct from the HMAC key and
  the old libsodium key). `seal()` always writes `enc2:` so values are portable:
  cPanel ea-php84 ships without ext-sodium, and openssl is everywhere. Older
  `enc1:` values (libsodium secretbox) are still read where ext-sodium is
  loaded; without it, reading one throws a clear error. A missing sodium
  extension is fine — Money settings shows a "Bank details encryption ready"
  check. `account_last4` keeps masked display.
  Legacy plaintext rows still read correctly; `scripts/encrypt-bank-details.php`
  seals them (idempotent). Keep `security_key` stable and backed up: changing
  it makes sealed numbers unreadable.
- **Withdrawal velocity.** Per earner per rolling 24 h: at most
  `money.withdraw_daily_count` (3) requests and `money.withdraw_daily_max_kobo`
  (₦500,000) in total; rejected requests do not count.
- **Bank-change cooldown.** Replacing a saved bank account pauses withdrawals
  for `money.bank_change_cooldown_hours` (24 h). Adding the first account, or
  re-saving the same one, does not.
- **Every five minutes** (`cron/every-five-minutes.php`): callbacks that were
  logged but never finished are re-run through the same verification
  (idempotent; a second failure is marked `retry_failed`), unpaid top-ups
  expire, and payouts with no callback after `money.payout_stuck_hours` (6 h)
  open a `payout_conflict` review item (the hold stays until AfrStar answers).
- **Receipts.** `receipt.php?id=<entry>` on the earner and advertiser portals
  (linked from wallet history): printable, owner-only.
- **Reports.** Admin → Money → Reports: daily totals per Lagos day and a
  ledger CSV export (audited; formula-safe).
- **Paystack name lookup** stays optional: only with
  `paystack_name_lookup.secret_key`. The Paystack bank code comes from
  `bank_code_map` or Paystack's bank list matched by name (cached 24 h).
- **Read-only SQL checks** in `database/verify/` (wallet/ledger, payment
  orders, campaign funding, pre-release); each returns rows only on a problem.

## Rules (admin-editable, `settings` table)

Min top-up ₦100, max ₦500,000; min withdrawal ₦1,000, max ₦500,000; unpaid
top-ups expire after 24 h (no money moves; a late confirmed payment still
credits). No top-up fee. Earner withdrawal fee 10 % by default (see below).
Top-up and withdrawal need a confirmed email.

## Earner withdrawal fee (migration 0014)

- **Rates.** Base rate `money.withdraw_fee_bps` (default 1000 = 10 %; Admin →
  Money → Money settings, field "Earner withdrawal fee (%)" next to the
  minimum withdrawal; 0–50 %, up to two decimals, 0 = free; audited as
  `admin.withdrawal_fee_changed` with the new rate in basis points as target
  id). Each earner level may set a lower rate (Admin → Growth → Earner
  levels, "Withdrawal fee (%)"; empty = base; a value above the base is
  refused on save, audited `admin.level_withdrawal_fee_changed`). If the base
  is later lowered below a level's rate, the base applies (shown on the
  levels page). With levels switched off, or before an earner reaches a
  level, the base rate applies.
- **Rounding.** fee = floor(gross × rate / 10000): rounded **down** to the
  kobo, in the earner's favour (`Money\Fees::withdrawal`).
- **Preview before submitting.** The withdraw form states the earner's
  rate. "Preview withdrawal" posts the amount; the server checks it and
  shows the amount, the fee (rate and level) and "You will receive" — nothing
  is created or held. "Confirm" posts the amount with the fee shown;
  `Withdrawals::request` recomputes it under the wallet lock and, if it no
  longer matches (admin changed the rate, the earner's level changed, or the
  form was tampered with), creates nothing and shows the new preview with
  "The fee changed". A POST without the confirm step only previews.
- **Locked at request.** The withdrawal stores `fee_bps`, `fee_kobo` and
  `fee_level_id`; approval pays `amount − fee` through AfrStar and the payout
  callback must carry that net amount. Later admin changes never affect a
  queued or processing withdrawal.
- **Refunds.** A rejected withdrawal or a failed payout returns the **full
  gross amount, fee included**, to the available balance (the fee is only
  booked when AfrStar confirms the payout).
- **Limits on the gross amount.** The minimum (₦1,000), maximum and 24-hour
  total are checked on the amount the earner enters, before the fee (the
  existing code compared the requested amount, so the minimum stays a gross
  minimum; ₦1,000 at 10 % pays out ₦900).
- **Pre-0014 withdrawals stay free:** the migration sets `fee_kobo = 0`,
  `fee_bps = 0` on every existing row, so they pay out in full.
- **Shown:** withdraw page (rate, preview, open request with fee and net),
  wallet list (fee, rate, "you receive" / "returned"), admin withdrawals list
  (fee, rate, "pays ₦…", approve confirmation with the net), Money settings
  badge.


## Referral commission (Phase 6)

When a referred advertiser's top-up is credited — by the AfrStar collection
callback or by an admin crediting a reviewed AfrStar payment (amount
mismatch) — the referrer earns `referrals.commission_bps` of the credited
amount (default 500 = 5 %, admin 0–50 % in Admin → Money → Referrals). It is
paid after the callback response is sent (with the "top-up credited"
notification) and caught up by the hourly cron if that step was missed; a
replayed or simultaneous callback pays once (unique per order). Admin wallet
credits, adjustments and bonuses never earn commission. Flagged referrals
earn nothing until an admin approves them; then earlier deposits are paid
at the rate in force at that time.

## Deposit cashback

On the same two credit paths (collection callback, admin review-credit of an
AfrStar payment) the depositing advertiser gets `cashback.rate_bps` of the
credited amount back into their wallet balance (rounded down to the kobo;
default 0 = off; admin 0–50 % and an optional minimum deposit
`cashback.min_deposit_kobo` in Admin → Growth → Referrals). It runs after
the response is sent, independently of the referral programme switch, and
pays once per order (`deposit_cashbacks`, key `cashback:<paymentOrderId>`).
A cashback error is logged and never affects the deposit; the hourly cron
retries deposits confirmed in the last 48 h. Admin wallet credits,
adjustments and bonuses never earn cashback, and cashback never earns
referral commission. The top-up page shows "Get X% cashback on deposits"
(and the minimum) only while the rate is above 0. There is no deposit
reversal in the system; an admin can reverse a paid cashback from the
cashback list (reason required).

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
- **Withdrawal (earners).** One saved bank account (bank from the AfrStar
  code list, 10-digit NUBAN, typed account name; changing it needs the
  password and emails an alert). A request moves the amount from available to
  held (`pending_review`); one open withdrawal per earner. Admin approves
  (status `processing` is committed first, then `/gateway/payout/` with order
  number `YTW<id>A<attempt>`) or rejects with a reason (hold released). A
  definitive AfrStar refusal returns it to review; an unclear outcome stays
  processing until the callback. Payout callback 1 = paid, 9 = refunded.
- **Admin.** Money → Wallets (totals, live AfrStar balance, reconciliation,
  user search, manual credit/debit with mandatory reason), Withdrawals,
  Payments & review (review queue, orders, raw callback log), Ledger, Money
  settings (min/max top-up and withdrawal, unpaid-order expiry).

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
credits). No fees (`Money\Fees` returns 0). Top-up and withdrawal need a
confirmed email.

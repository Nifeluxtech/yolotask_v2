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

## Rules (admin-editable, `settings` table)

Min top-up ₦100, max ₦500,000; min withdrawal ₦1,000, max ₦500,000; unpaid
top-ups expire after 24 h (no money moves; a late confirmed payment still
credits). No fees (`Money\Fees` returns 0). Top-up and withdrawal need a
confirmed email.

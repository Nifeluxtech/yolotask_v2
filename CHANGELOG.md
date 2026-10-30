# Changelog

## Phase 3 gaps closed (with Phase 4)

- Bank account numbers encrypted at rest (libsodium, `enc1:`), last 4 digits kept for display; `scripts/encrypt-bank-details.php` seals existing rows.
- Withdrawal velocity limits (3 per 24 h, ₦500,000 per 24 h) and a 24 h withdrawal pause after a bank account change; all admin-editable in Money settings.
- `cron/every-five-minutes.php`: retries unfinished AfrStar callbacks, expires stale top-ups, flags payouts with no callback after 6 h for review.
- Printable wallet receipts for earners and advertisers; admin Money → Reports with daily totals and ledger CSV export.
- Paystack name lookup finds the Paystack bank code from Paystack's bank list (still optional, only with a key); bank page shows when the name was confirmed.
- Read-only verification SQL in `database/verify/`; docs/callbacks-and-reconciliation.md.

## Phase 4 — Campaigns, task feed, submissions and proofs

- Admin catalog: platforms (with allowed link domains) and task types with base and premium advertiser price and earner reward; seeded with 8 platforms and 7 task types (premium = base × 1.5). Margin goes to a `system:revenue` ledger account on approval.
- Advertiser campaigns: draft → fund (full budget locked in a per-campaign escrow ledger account, idempotent) → admin approval → live; pause/resume, cancel (unspent escrow refunded, pending proofs stay reserved), end date (cron), automatic completion with leftover refund. https-only, platform-checked, normalised links with a duplicate guard. Versioned instructions. Live cost preview, progress bars, funding history.
- Earner task feed with platform filter; start reserves a slot (60 min, max 3 at once) under a row lock so slots can never be over-claimed; release; one submission per campaign; proof screenshot upload (JPEG/PNG/WebP ≤ 2 MB, validated and re-encoded with GD, reused screenshots refused) stored outside public roots and served only to the earner, the campaign's advertiser and admins; resubmission after rejection (cap 1).
- Advertiser review: approve (earner paid, margin to revenue) or reject with reason (earner emailed, slot back in the pool); auto-approval after the advertiser's window (default 48 h, 12–336 h); optional reward hold window with hourly release.
- Admin: campaign approval queue and all campaigns, campaign detail with stop/refund, all submissions with stale filter and proof viewing, task settings.
- Dashboards: real marketplace stats for earners, advertisers and admins; Tasks/My work (earner), Campaigns (advertiser) and Tasks (admin) in the bottom dock.
- Cron: `every-fifteen-minutes.php` (reservations, auto-approve, end dates), `hourly.php` (reward release + escrow reconciliation), `every-minute.php` (optional); daily reconciliation now covers escrow, revenue and reward hold.
- Migrations `0005_campaigns_funding_links.sql` and `0006_tasks_submissions_proofs_appeals.sql` (additive). Integration test `tests/Integration/TaskSubmissions/phase4_test.py`.

## Phase 3 — Wallets, ledger and AfrStar payments

- Double-entry ledger in integer kobo (`Money\Ledger`): balanced postings only, unique idempotency keys, row locks, no negative wallets; wallet balances cached on `wallets` and `ledger_accounts`.
- One NGN wallet per earner/advertiser (created lazily) with available and held balances; wallet page with history; wallet balance on dashboards and a Wallet item in the bottom dock.
- AfrStar v2 client (`/gateway/payin/`, `/gateway/payout/`, `/gateway/balance/`), MD5 ordered-value signing, configurable base URL; credentials from `config-private/afrstar.key` (`MERCHANT_SN,MERCHANT_AK,MERCHANT_SK`) or `payments.local.php`. Pages say "Payments are not available yet" when not configured.
- Advertiser top-ups: order → AfrStar hosted page → return page that refreshes until confirmed. Credited only by a verified collection callback; amount mismatches go to an admin review queue and are never auto-credited.
- Earner withdrawals: one bank account (full AfrStar bank-code list, 10-digit NUBAN, password re-entry and security email on change), request holds funds, one open withdrawal at a time, admin approve (AfrStar payout) or reject (hold released); payout callback pays out or refunds and emails the earner.
- Public callback endpoints `/hooks/afrstar/collection.php` and `/hooks/afrstar/payout.php` on the main site: raw callbacks logged, `SUCCESS` only after commit, idempotent, non-200 otherwise.
- Admin Money section: wallet totals, live AfrStar balance, reconciliation button, user wallet search, manual credit/debit with mandatory reason (ledgered, audited), withdrawals queue, payment review queue, top-up orders, raw callback log, ledger transactions, money settings (min/max top-up and withdrawal, unpaid-order expiry).
- `cron/daily.php` expires unpaid top-ups after 24 h (no money moves) and runs the reconciliation (exit 2 on mismatch).
- Migrations `0003_wallets_ledger.sql` and `0004_payments_banks_withdrawals.sql` (additive). Integration test `tests/Integration/PaymentCallbacks/phase3_test.py` with a local fake AfrStar gateway.

## Phase 2 — Accounts and security

- Email verification after sign-up: 6-digit code or one-tap link, 30-minute expiry, five tries, 60-second resend cooldown.
- Forgot/reset password on every portal: single-use 30-minute links, all devices signed out, confirmation email.
- Account page: profile (name, phone in E.164), change password, signed-in devices with per-device and all-other sign-out, close account.
- Staff sign-in for reviewer and admin portals; staff are invite-only (48-hour set-password link). `scripts/create-staff.php` creates the first admin.
- Admin Users page: search and filter, suspend or restore (sessions revoked, last admin protected), invite reviewers and admins.
- Sign-up protection: hidden bot field, signed form timing, throwaway-domain block, 5 sign-ups per IP per hour, reset throttles.
- Security emails: new-device sign-in, password changed, account closed. Branded HTML email from no-reply@yolotask.com.ng.
- Migration runner `scripts/migrate.php` with `schema_migrations`, run automatically by the cPanel release; adopts databases where 0001 was applied by hand.
- Migration `0002_account_security.sql` (additive). Daily cleanup in `cron/daily.php`.

## Phase 1 — Shared design system and protected dashboards

- Shared Deepwater design system (`shared/assets`), built into every portal by `scripts/build-assets.sh` (output committed; no server build step).
- Shared public, auth, authenticated, document and error layouts with glass header, light/dark toggle, floating bottom nav and page transitions.
- Protected earner and advertiser dashboards: signed-out visitors are redirected to sign in, other roles receive 403.
- Production copy on all five portals; Terms, Privacy and Refunds pages on the main site.
- Portal URLs derived from config `base_urls` or the current host, so one release serves production and staging.
- No database migration.


## Unreleased

- Initial YOLOTASK V2 repository scaffold.

# Changelog

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

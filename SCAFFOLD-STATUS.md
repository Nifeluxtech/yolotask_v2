# Current implementation status

The repository remains an incomplete YOLOTASK V2 starter, not a production marketplace. The first authentication/database foundation slice is now present on the feature branch.

## Implemented in this slice

- Earner and advertiser registration routes with server-assigned roles.
- Password hashing and verification through PHP's password API; registration requires 12–72 bytes.
- CSRF-protected registration/login forms and POST-only logout.
- Login is bound to the expected role and uses generic credential errors.
- PHP session ID rotation at login, per-role host-only cookie names, database session-hash records, fixed 12-hour expiry, revocation on logout, strict/cookie-only session mode.
- Five-failure/15-minute login throttling per HMACed email or IP, using a private key.
- Initial `0001_users_sessions_security.sql` schema and a policy smoke test.
- Mobile-first registration and sign-in forms for earner and advertiser apps.

## Phase 2 (accounts and security)

Email verification, password reset, account settings and devices, staff invitations and sign-in, admin user management, sign-up abuse controls, security emails, the migration runner and daily cleanup are implemented. See CHANGELOG.md.

## Phase 3 (wallets, ledger, AfrStar payments)

Wallets, the double-entry ledger, AfrStar top-ups and withdrawals, signed callbacks, the payment review queue, admin money tools, money settings and daily reconciliation are implemented and tested locally against a fake AfrStar gateway. Live AfrStar behaviour (real pay pages, callback delivery, payout timing) still has to be verified on staging. See CHANGELOG.md, docs/payments.md and hooks/README.md.

## Still incomplete or requiring review

MFA, database integration tests in CI, and a full independent security review are not yet done. The first migration has not been tested against the target cPanel MySQL/MariaDB version; apply it to staging only after review.

Campaigns/tasks, proofs/appeals, growth, virtual numbers, notifications, and admin/reviewer features remain unimplemented. Migration files after 0004 (and 0002_settings_catalog_interests.sql) and the remaining feature-area PHP files are explicit placeholders.

Before production, resolve the roadmap's open choices (reviewer compensation, virtual-number eligibility, referral qualification, level/check-in rules, stale/hold/resubmission periods, final appeal path, Afrstar status-query/IP rules, fee treatment). Review provider API contracts, cPanel public roots, storage, cron, SSL, database and restore capabilities. The primary domain may be fixed to `public_html`; confirm the apex mapping with the host.

Do not use public registration with live users or real data until email verification, abuse controls, password reset, account protections, integration tests, and independent security review are complete.

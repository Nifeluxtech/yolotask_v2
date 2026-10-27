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

## Still incomplete or requiring review

Email verification, password-reset delivery and use, account recovery, registration abuse controls, MFA, staff account provisioning, protected product dashboards, database integration tests, session cleanup, and a full security review are not implemented. The first migration has not been tested against the target cPanel MySQL/MariaDB version; apply it to staging only after review.

Wallet/ledger posting, fees, payments/callbacks, reconciliation, withdrawals, campaigns/tasks, proofs/appeals, growth, virtual numbers, notifications, and admin/reviewer features remain unimplemented. Migration files after 0001 and feature-area PHP files remain explicit placeholders.

Before production, resolve the roadmap's open choices (reviewer compensation, virtual-number eligibility, referral qualification, level/check-in rules, stale/hold/resubmission periods, final appeal path, Afrstar status-query/IP rules, fee treatment). Review provider API contracts, cPanel public roots, storage, cron, SSL, database and restore capabilities. The primary domain may be fixed to `public_html`; confirm the apex mapping with the host.

Do not use public registration with live users or real data until email verification, abuse controls, password reset, account protections, integration tests, and independent security review are complete.

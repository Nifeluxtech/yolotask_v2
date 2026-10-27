# Scaffold status and production blockers

This archive is safe to extract into a **new** repository. It is not feature-complete and must not be represented as a ready-to-launch payment marketplace.

Included as working starter code:
- plain-PHP front controllers for all five apps;
- shared bootstrap/config loader and small HTTP, logging, validation, database, and CSRF helpers;
- mobile-first app shell and responsive CSS;
- cPanel versioned-release deploy script with clean-tree checks;
- private-config and private-runtime directory conventions;
- Codespaces import and staging/deployment instructions.

Intentionally not implemented yet:
- authentication and password reset flows;
- wallet/ledger posting, fee calculations, payments, callbacks, reconciliation, and withdrawals;
- campaigns, tasks, proof uploads, appeals, growth, virtual numbers, notifications, and admin/reviewer actions;
- executable database migrations, seed data, migration runner, and populated tests;
- production Afrstar/Paystack/Resend/OneSignal/FiveSim/SMSPool integrations.

All empty PHP feature files and SQL migration files contain explicit scaffold markers. Do not apply them or expose them as functioning features.

Before production work, resolve the roadmap's open choices (reviewer compensation, virtual-number eligibility, referral qualification, level/check-in rules, stale/hold/resubmission periods, final appeal path, Afrstar status query/IP rules, and fee treatment). Obtain and review provider API contracts. Add server secrets only in private cPanel configuration. Confirm the cPanel host can use the planned PHP version, CLI, public roots, release symlink, storage permissions, database features, cron, SSL, and outbound provider traffic. The primary domain may be fixed to `public_html`; ask the hosting provider to confirm the apex-domain mapping.

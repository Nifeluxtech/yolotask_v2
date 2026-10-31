# Database

Executable migrations: `0001_users_sessions_security.sql`, `0002_account_security.sql`, `0003_wallets_ledger.sql` (settings, wallets, ledger accounts/transactions/entries), `0004_payments_banks_withdrawals.sql` (payment orders, raw callbacks, payment review queue, bank accounts, withdrawals), `0005`/`0006` (campaigns, tasks), `0007_afrstar_callback_forwards.sql` (AfrStar hub log; `0007_growth_referrals_*` beside it is still a placeholder). `0002_settings_catalog_interests.sql` remains a placeholder. Files marked "NOT executable" are placeholders for later phases and are skipped.

`scripts/migrate.php` applies pending migrations once each, in filename order, and records them in `schema_migrations`. The cPanel release script runs it automatically before switching traffic. A database where 0001 was applied by hand is adopted without re-running it.

Manual run: `php scripts/migrate.php --config-dir=$HOME/yolotask-v2-live/config-private` (add `--status` to list pending files). Take a backup before migrating production.

First admin: `php scripts/create-staff.php --name="Full Name" --email=you@example.com --role=admin --config-dir=$HOME/yolotask-v2-live/config-private` emails a 48-hour set-password link.

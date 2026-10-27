# Database foundation status

`migrations/0001_users_sessions_security.sql` creates the initial user, session, login-attempt, password-reset-token, and audit tables. Later numbered SQL files remain placeholders and must not be run.

Apply migration 0001 manually to a fresh **staging** database after verifying the MySQL/MariaDB version, creating the database/user, and taking a backup. There is not yet a migration runner or migration-history table. Record the migration in the deployment log. Do not apply it to production before engine-level testing and review.

Configure `database.local.php` and `app.local.php` outside the repository in the private runtime config directory. The authentication throttle and privacy hashes require a unique random `security_key` of at least 32 characters. Never commit that value.

Run the policy smoke test from the repository root with `php tests/smoke/auth-policy.php`. Database integration tests are not yet present.

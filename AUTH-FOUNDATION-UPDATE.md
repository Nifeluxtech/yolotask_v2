# Apply the auth foundation update in Codespaces

This update is for the existing `feat/auth-and-database-foundation` branch. It replaces only the auth-related placeholders and adds registration/login routes; it does not implement payments or wallet changes.

1. In Codespaces, verify the branch and save any work already in progress:

   ```sh
   git branch --show-current
   git status --short
   ```

   Continue only when the current branch is `feat/auth-and-database-foundation` and the working tree is clean, or commit/stash your existing edits first.

2. Download `yolotask-auth-foundation-update.zip`. On GitHub, select the existing `feat/auth-and-database-foundation` branch, use **Add file → Upload files**, and commit the ZIP to that same branch. Back in Codespaces, pull it with `git pull --ff-only origin feat/auth-and-database-foundation`. If you can copy files directly into the Codespace, use the browser editor's upload/drag option or `gh codespace cp ./yolotask-auth-foundation-update.zip remote:/workspaces/REPO_NAME/` instead; in that case, skip the pull.

3. From the repository root, extract the overlay. It contains only the changed/new auth files. The `-o` option replaces matching scaffold placeholders:

   ```sh
   unzip -o yolotask-auth-foundation-update.zip -d .
   ```

   Then remove the uploaded patch archive so it is not kept as a repository file:

   ```sh
   rm yolotask-auth-foundation-update.zip
   ```

   The files are designed to replace the auth placeholders in this scaffold.

4. Run syntax and policy checks:

   ```sh
   find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
   php tests/smoke/auth-policy.php
   ```

5. Review the changes and commit only on this feature branch:

   ```sh
   git status --short
   git diff --check
   git add .
   git commit -m "Implement auth and database foundation"
   git push
   ```

6. Open/update the pull request into `main`. Do not merge until you have reviewed the migration and code.

## Staging database only

The update includes `database/migrations/0001_users_sessions_security.sql`. Do not apply it in production. First create a disposable staging MySQL/MariaDB database, verify host compatibility, configure private `database.local.php` and `app.local.php`, set a unique random `security_key` of at least 32 characters, and apply the migration manually. Database integration tests and a migration runner are not yet included.

Email verification, password reset, registration abuse protections, MFA, and protected product dashboards remain incomplete. Keep public registration and real user data disabled until those features are tested and reviewed.

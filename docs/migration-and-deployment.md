# YOLOTASK V2 — GitHub-to-cPanel Migration and Deployment Guide

**Purpose.** This runbook defines how the new private YOLOTASK PHP monorepo is developed, tested, migrated, and deployed to cPanel. GitHub is the source of truth. cPanel holds a managed deployment clone and runs the deployed application. It is not the development workspace.

**Scope.** This covers environment preparation, cPanel Git setup, staging and production releases, numbered database migrations, V1 Supabase data migration, cutover, and rollback. It does not authorize a production deployment or replace host-specific cPanel instructions.

## 1. Deployment model and decisions to confirm

Use one private GitHub repository for the new PHP application. Keep its cPanel-managed clone outside every website document root, for example `~/repositories/yolotask-v2`. Do not edit application files in cPanel. Developers work in GitHub-connected development workspaces and merge reviewed changes into the release branch.

Use the cPanel Git Version Control **pull deployment** flow: cPanel clones the private GitHub repository; an authorized operator selects **Update from Remote** to retrieve a reviewed commit; then selects **Deploy HEAD Commit** to run the repository's top-level `.cpanel.yml` deployment tasks. The cPanel documentation describes this as a manual pull-and-deploy flow. It does not deploy each GitHub push automatically. [1] [2]

For production, prefer a versioned release target with a deliberate activation step rather than pointing the live sites at a Git working tree while it is being updated. The repository remains outside the webroots. The `.cpanel.yml` deployment task can be designed to publish a reviewed release to a separate application directory and then switch the live roots, if the host supports that layout. Confirm symlink, file ownership, and document-root behavior with the provider before relying on a release-pointer swap. If the host cannot support this, deploy into fixed target directories during an approved maintenance window and test the exact process on staging first.

The supplied roadmap maps the primary domain to `apps/main/public`. Confirm this with the host before building the release mechanism: cPanel states that the primary domain's document root cannot be changed from `public_html` through the standard account interface. A provider-level configuration or an agreed `public_html` arrangement may be necessary. Do not assume the main-domain mapping can be changed in the same way as a subdomain. [3]

Set up a separate staging deployment and database. Staging should use non-production credentials and provider test/sandbox settings where available. Production should deploy only a reviewed release commit or tag. Keep the V1 application available read-only during the agreed rollback window after cutover.

## 2. Roles and release authority

Assign named owners before the first deployment:

- **Application maintainer:** reviews code, migrations, security checks, and release notes.
- **cPanel operator:** manages SSH access, cPanel Git, document roots, PHP settings, cron entries, and deployment execution.
- **Data migration owner:** exports V1 data, maintains the mapping, runs dry runs, and signs off reconciliation.
- **Payment operator:** verifies Afrstar credentials, callback configuration, source-IP requirements, small-value test transactions, and payment reconciliation.
- **Release approver:** authorizes production cutover and rollback decisions.

One person may hold multiple roles, but production payment verification and financial reconciliation should receive an independent review wherever practical.

## 3. Repository and GitHub preparation

### 3.1 Branch and tag policy

Follow the roadmap's branch convention: `dev` is the integration branch and `main` is production. Use short-lived feature branches for work. Require pull-request review before merging into either long-lived branch. Protect `main` from direct pushes and require automated checks to pass.

Deploy staging from a selected `dev` commit or release-candidate tag. Deploy production from an approved commit on `main`, preferably recorded with an immutable release tag such as `v2.0.0-rc.1` or `v2.0.0`. Record the full commit SHA, tag, migration range, and release notes in the deployment log.

Do not use branch names alone as the audit record. A branch can advance after testing; a commit SHA and release tag identify the exact code tested and deployed.

### 3.2 Keep secrets and runtime data out of Git

Commit only example configuration files. Keep real database credentials, Afrstar keys, Paystack secret, mail/push keys, SMS provider keys, encryption keys, and private storage paths in private cPanel configuration outside all public roots and outside the repository clone. Never put production secrets in GitHub Actions logs, issue attachments, release notes, or `.cpanel.yml`.

Keep uploaded proofs, logs, cache, backups, database dumps, and migration extracts out of the repository. The repo's `.gitignore` should explicitly exclude them, but `.gitignore` is not a substitute for storing them outside the clone. Ensure that example config contains dummy values only.

### 3.3 Checks before a commit is release-ready

For each release candidate, verify at minimum:

- all automated unit, integration, migration, and security tests pass on a supported PHP version;
- all PHP source files pass syntax checks;
- the repository contains no secrets, customer exports, logs, proofs, or production configuration;
- numbered database migrations apply to an empty test database and to a copy of the prior schema;
- ledger tests cover transaction rollback, duplicate references, no-overdraft behavior, and concurrent writes;
- callback tests cover valid callbacks, invalid signatures, amount/order mismatches, retries, and duplicate delivery;
- the authorization matrix covers each role, ownership boundaries, status transitions, and cross-subdomain access;
- the release note lists new migrations, configuration changes, cron changes, callback changes, and rollback limitations.

## 4. cPanel preflight

### 4.1 Confirm hosting capabilities

Ask the hosting provider to confirm the following before implementation is considered deployable:

1. cPanel Git Version Control is enabled for the account and private SSH cloning is permitted.
2. The cPanel account can use SSH or Terminal for key setup and operational checks.
3. The installed PHP version and required extensions are available to every app domain, and the matching PHP CLI binary is available for cron jobs and migrations. cPanel's MultiPHP Manager controls the PHP version for each virtual host, so verify each domain rather than assuming one setting applies to all. [4]
4. The host permits the planned document roots and private application/release directories. Confirm the primary-domain `public_html` constraint specifically. [3] [5]
5. The host's deployment execution supports the planned `.cpanel.yml` tasks, required shell commands, file ownership, and any release activation mechanism.
6. The host supports the database engine/version, required MySQL/MariaDB features, database/user limits, connection limits, backup/restore, and database size required by the roadmap.
7. Required outbound connections to Afrstar, Paystack name lookup, Resend, OneSignal, FiveSim, and SMSPool are allowed.
8. The server's outbound public IP and any callback ingress rules meet Afrstar's allowlist and callback requirements. If Afrstar requires a dedicated IP, arrange that before live testing.
9. SSL can be provisioned for the apex domain and all four role subdomains. Confirm DNS control and expected propagation before cutover.
10. Cron Jobs, sufficient storage, log access, and a restore path are available.

If any requirement is unavailable, change the deployment design or hosting plan before the application depends on it.

### 4.2 Confirm cPanel document roots

Create or verify the five hostnames and their planned document roots. cPanel's Domains interface provides document-root management for domains and subdomains, subject to hosting-provider settings. [5]

| Host | Planned app public directory |
|---|---|
| `yolotask.com` | `apps/main/public` |
| `earner.yolotask.com` | `apps/earner/public` |
| `advertiser.yolotask.com` | `apps/advertiser/public` |
| `reviewer.yolotask.com` | `apps/reviewer/public` |
| `admin.yolotask.com` | `apps/admin/public` |

For the primary domain, resolve the `public_html` limitation before accepting the tree as final. Possible host-approved approaches include retaining `public_html` as the apex document root while safely mapping it to the main app's public entry point, or having the provider make an account-level arrangement. Do not place the repository root, configuration, shared source, migrations, cron scripts, or storage under a public document root.

### 4.3 Prepare staging and production infrastructure

Create separate staging and production databases with separate credentials. Create private configuration files for each environment. Create private runtime directories for proofs, logs, cache, exports, and backups; use least-privilege permissions and verify the web/PHP process can write only where required.

Set up SSL for each domain before testing login, password reset, payment callbacks, and session cookies. Keep role-app session cookies scoped to their respective subdomains as specified by the architecture. Do not use a wildcard cookie domain unless the security design explicitly requires it.

## 5. Configure cPanel access to the private GitHub repo

Use a dedicated, revocable, **read-only** SSH deploy key for the cPanel environment. Do not reuse a developer's personal SSH key. cPanel's documented private-repository setup requires SSH access, adding the cPanel public key to the repository host, and testing authentication. Its GitHub example allows write access only if cPanel needs to push back; this deployment workflow should not grant that permission. [6]

1. In cPanel SSH Access or Terminal, create a repository-specific SSH key according to the provider's approved key policy.
2. Add the public key to the new GitHub repository as a read-only deploy key. Keep the private key only on the cPanel account.
3. Test the key against GitHub from cPanel. Verify the SSH host key prompt/fingerprint rather than blindly accepting a changed host key.
4. In cPanel → Files → Git Version Control, clone the GitHub repository into a private path such as `~/repositories/yolotask-v2`. Avoid whitespace and special characters in the repository path. cPanel documents path restrictions and requires private-repository setup for private clones. [7]
5. Confirm the remote URL points to the intended private repo, the correct default branch is selected, the expected commit is present, and the working tree is clean.
6. Do not push from cPanel to GitHub. Do not edit the cloned files with File Manager. Make changes in GitHub-connected development, then pull them to cPanel.

The cPanel Git interface supports an **Update from Remote** action and uses fast-forward-only pulls. A divergent or dirty cPanel working tree can prevent an update, which is another reason not to make server-side edits. [7]

## 6. Prepare the deployment definition

A cPanel-managed repository requires a valid `.cpanel.yml` in the repository's top-level directory for deployment actions. The repository must have at least one branch and a clean working tree. The YAML task list defines the commands that publish files. cPanel warns against wildcard deployment commands because they can include `.git` or other unintended files. [8]

Before writing the production deployment commands, decide which supported layout will be used:

- **Release-directory layout:** publish an identified commit into a new versioned application directory. Point all five domains at a stable `current` location that switches to the new release in one controlled step. Use only if the hosting provider confirms symlink/document-root behavior and permissions.
- **Fixed deployment-directory layout:** publish approved files into fixed app directories and place the domains' document roots there. Because a multi-directory copy can be partially complete if interrupted, run it on staging first and use maintenance mode or an equivalent protection during production publication.
- **Direct Git working-tree layout:** set each document root inside the cloned repo. This is simplest, but pulling updates changes the files served by the site before a separate review/publish gate. Use only if that immediate-update behavior is explicitly accepted and the production clone receives only approved release commits.

For YOLOTASK, prefer a private managed clone plus a deliberate publish step. The exact `.cpanel.yml` must use the actual cPanel home path, target paths, PHP binary, directory ownership, and host-supported commands. Test it against a disposable staging target. Ensure it does not copy `.git`, `config` secrets, runtime storage, exports, backups, or V1 data into public paths. Keep database migrations out of automatic deployment tasks; migrations require a backup, a controlled operator step, and verification.

A deployment task should fail clearly if a required source directory is absent, if the expected release SHA is not the one approved, or if validation fails. Preserve the last known-good release until the new one passes smoke tests. cPanel's deployment interface runs the checked-in tasks when **Deploy HEAD Commit** is selected. [8]

## 7. Initial staging deployment

### 7.1 Deploy the application shell

1. Merge the first reviewed app-shell release into `dev` and tag or record the exact staging commit.
2. Confirm the cPanel staging clone has no local changes. In Git Version Control, use **Update from Remote** to retrieve the target commit. Confirm the displayed HEAD SHA before deploying.
3. Use **Deploy HEAD Commit** to run the staging publication tasks. Inspect the deployment result and cPanel deployment log if it fails.
4. Provision staging runtime configuration outside the public roots. Verify the environment is explicitly marked non-production and points only to the staging database and sandbox/test provider credentials.
5. Create the staging database and apply migrations in order. Seed only safe default records and a controlled staging admin account.
6. Set PHP version and extensions separately for each staging virtual host through the available cPanel controls. Confirm the CLI binary used by cron matches the site's runtime version. [4]
7. Verify the private proof-storage path is not URL-addressable. Upload a test proof and confirm an unauthorized user cannot retrieve it.
8. Add only the needed staging cron jobs, initially disabled or configured with harmless test data. cPanel cron commands use an absolute command path; allow enough interval for prior jobs to finish so duplicate overlapping runs do not degrade the server. [9]

### 7.2 Run staging acceptance tests

Test all five hostnames over HTTPS. Verify the correct app loads, the role boundary is enforced, and each subdomain gets the intended session behavior. Test public pages, registration/login, password reset, role routing, CSRF rejection, rate limits, 404/500 behavior, and maintenance mode.

Exercise a complete staging campaign lifecycle, including creation, task claim, proof submission, review, appeal, reward release, pause/resume, completion at the participant limit, and ledger entries. Exercise payment callback validation with test fixtures or provider sandbox transactions. Confirm a mismatched payment is held rather than credited and a repeated valid callback is idempotent.

Run database verification scripts for wallet-vs-ledger, campaign funding, payment orders, and referral awards. Confirm scheduled jobs are idempotent and that a second run does not double-credit, double-refund, or duplicate decisions.

Record the tested commit SHA, migration numbers, environment settings changed, smoke-test evidence, open defects, and the operator who signed off.

## 8. Production release procedure

Use this procedure for each production release after the initial launch.

### Before pulling

1. Confirm the release is approved on GitHub, CI checks pass, the production tag points to the reviewed commit, and the deployment window is agreed.
2. Confirm the production environment is healthy and current. Check available disk space, PHP version/extensions, database availability, storage writability, SSL status, provider connectivity, and cron health.
3. Confirm no production files have been edited in cPanel and the cPanel Git working tree is clean.
4. Read the release notes. Identify schema changes, long-running backfills, callback changes, cron additions, one-time configuration changes, and irreversible actions.
5. Confirm a current database backup exists and that a restore test has been completed recently. A backup that has never been restored is not sufficient evidence of recoverability.
6. For releases involving payments, wallet balances, task rewards, bank details, or callbacks, require a financial/security reviewer sign-off.

### Pull and publish

1. In cPanel Git Version Control, open the production repository and confirm the selected production branch.
2. Select **Update from Remote**. This retrieves changes but does not by itself run the `.cpanel.yml` deployment task in cPanel's documented manual pull flow. [2]
3. Confirm the resulting HEAD SHA exactly matches the approved GitHub tag/commit. Verify the working tree is clean.
4. Select **Deploy HEAD Commit** to run the reviewed publish tasks. Do not deploy an unexpected HEAD or an unreviewed branch tip. [2] [8]
5. Confirm the new release is active and all five document roots resolve to the intended public directories. Confirm configuration and storage remain outside all document roots.
6. Apply database migrations only after following the migration procedure in Section 9. Avoid running irreversible migrations automatically from deployment tasks.
7. Enable newly required cron jobs only after the deployed code and database are compatible. Log the schedule and command paths.

### Smoke test and sign-off

Immediately test the home page, each role login, a representative protected page, password reset, the admin health view, and one read-only database-backed operation. Check PHP/error logs and deployment output. Verify callbacks still point to the production URLs and that production secrets were not replaced by staging values.

For financial releases, run reconciliation checks and inspect recent ledger entries and payment states before closing the deployment window. If a required check fails, place the affected functionality in maintenance mode or revert traffic to the last known-good release according to the rollback plan. Do not leave a partial release serving some subdomains on old code and others on new code.

## 9. Database migration procedure

### 9.1 Migration policy

Use numbered, ordered migrations. Treat an applied migration as immutable. Add a new migration rather than editing one already used by staging or production. Prefer additive changes first: add nullable columns or new tables; deploy code that can read both old and new shapes; backfill; then remove obsolete structures only in a later, separately approved release.

Do not rely on a Git checkout to undo a database migration. Application rollback and database rollback are different operations. Where a migration is not safely reversible, document that explicitly and rely on a tested restore or forward-fix plan.

### 9.2 Routine schema migration

1. Read the migration and identify locking, table rebuild, default-value, index, charset/collation, foreign-key, and data-retention effects.
2. Apply it to a disposable database copy and measure execution time with production-like volume.
3. Back up the production database and confirm backup completion before applying the migration.
4. Put affected write paths into maintenance/read-only mode if the migration is incompatible with concurrent requests.
5. Apply migrations in order using the approved CLI or database interface and the production database credentials stored outside the public tree. Record the exact migration number and operator.
6. Run the migration verification queries and application smoke tests before reopening writes.
7. If it fails, stop. Do not repeat a partially applied migration blindly. Inspect database state, consult the migration's recovery notes, and choose a documented restore or forward-fix path.

### 9.3 Financial schema safeguards

For ledger or wallet schema changes, confirm transaction boundaries and database constraints before production. Do not set balances through a one-off SQL update as a migration shortcut. Any required adjustment must have a traceable source, approval, audit record, and ledger entry through the approved financial process.

Run at least the wallet balance versus ledger reconciliation and campaign funding reconciliation before and after a financial migration. Verify duplicate references remain rejected, ledger rows are append-only under application permissions, and callbacks cannot create duplicate financial entries.

## 10. V1 Supabase-to-MySQL/MariaDB migration and cutover

The data migration is a separate controlled project, not an incidental deployment step. The roadmap requires importing users without passwords, wallets and ledger history, campaigns, and referrals; flagging unmappable records; reconciling balances; and rehearsing on a copy before cutover.

### 10.1 Inventory and mapping

1. Inventory V1 Supabase tables, auth identities, row counts, types, relationships, status enums, RLS policies, storage references, payment references, and provider identifiers.
2. Produce a signed-off mapping from each V1 table/field to the V2 schema. Define explicit transformations for role values, currency amounts, timestamps/time zones, nullable fields, duplicate/invalid records, and status transitions.
3. Decide how V1 IDs map to V2 IDs. Keep a durable source-ID-to-target-ID map so the import is reproducible and relationships remain traceable.
4. Decide which history is imported, which proof files remain available, and how external payment identifiers and audit history are preserved.
5. Define reconciliation totals before any import: user counts by role/status, wallet balance totals, ledger totals by type, campaign funding and remaining liability, active submissions, pending withdrawals/deposits, and referral awards.

### 10.2 Dry runs

1. Export a consistent V1 snapshot through an authorized, secure process. Encrypt the export at rest and transfer it through a restricted channel. Never put exports or credentials in Git.
2. Restore the snapshot into an isolated migration environment. Do not use live payment credentials or public callbacks in that environment.
3. Run the import repeatedly against clean target databases to prove it is deterministic and does not duplicate users, ledger entries, campaigns, or awards.
4. Generate an exception report for unmappable or contradictory records. Resolve or explicitly quarantine each exception; do not silently drop it.
5. Compare source and target control totals. Investigate every unexplained difference. The roadmap's migration exit condition is zero unexplained reconciliation difference.
6. Time the complete final export/import/reconciliation process and confirm it fits the approved maintenance window.

### 10.3 Final migration and cutover

1. Announce the planned window and freeze new V1 writes at the agreed time. Disable or pause campaign/task/payment actions that could change balances or source records.
2. Reconcile in-flight deposits, payouts, callbacks, withdrawals, and refunds with the payment operator. Record the final V1 data watermark and outstanding external transactions.
3. Take and verify the final V1 export. Import it to the production V2 database using the rehearsed procedure.
4. Run all reconciliation checks and review quarantined records. Do not switch users to V2 while unexplained financial differences remain.
5. Provision V2 user accounts without carrying over old password hashes unless explicitly reviewed and proven compatible. Send role-appropriate password reset instructions as planned.
6. Point the five hostnames to the approved V2 public roots, or activate the release root, after SSL, DNS, callback and permission checks. Keep V1 read-only and available during the rollback period.
7. Monitor login/reset activity, payment callbacks, failed jobs, application errors, wallet/ledger differences, and support reports closely after cutover.
8. End the rollback window only after the agreed stability period and an explicit reconciliation sign-off.

Do not dual-write balances to V1 and V2 unless a separately designed and tested synchronization protocol exists. Two independent sources of truth create a high risk of balance drift.

## 11. Rollback and recovery

### Application-only failure

If the database schema remains compatible with the previous application release, restore the prior release pointer or republish the previous tagged release. Confirm all five sites use the same version. Keep logs and deployment metadata for the failed release.

### Schema-related failure

Do not assume that restoring old PHP files reverses the schema. Stop writes if needed, assess whether the migration is backward compatible, and use the documented forward fix or a tested database restore. A restore may discard legitimate transactions created after the backup, so decide the recovery point with the data and payment owners before executing it.

### V1 cutover failure

If V2 is not stable during the rollback window, disable new V2 writes, reconcile V2 transactions created since cutover, and follow the agreed plan to resume V1 read-only or restore V1 service. Never simply switch DNS back while V2 has accepted financial activity without first accounting for it. Preserve both systems' audit and ledger evidence.

The rollback plan must name the decision-maker, technical operator, restoration point, V2 transaction reconciliation process, user communication owner, and conditions for resuming service.

## 12. Cron, callbacks, and operational monitoring

Create production cron jobs only after the relevant feature is deployed and tested. Use the cPanel Cron Jobs interface with absolute paths and the intended PHP CLI binary. cPanel recommends allowing enough time for each run to finish before another scheduled run begins; overlapping jobs can degrade the server. [9]

Use the roadmap's intended intervals as the initial schedule: every minute for reservation expiry and number polling/refunds; every five minutes for callback retries and stale payment checks; every fifteen minutes for stale review and eligible auto-approval; hourly for reward release and leaderboard refresh; daily for reconciliation/cleanup/backup; and weekly/monthly for leaderboard-period close. Confirm actual job duration and host limits before enabling the full schedule.

For every job, record its command path, schedule, timeout expectations, lock/idempotency method, log path, alert owner, and manual recovery procedure. Ensure a stuck run cannot be duplicated by the next interval. Use dry-run mode where available before financial actions are enabled.

For Afrstar callbacks, confirm the production collection and payout URLs, signature verification, expected content type/response, server time, amount precision, merchant identifiers, allowed source IPs, retry behavior, and whether an order-status query endpoint exists. Callback URLs must be switched only after the production deployment and SSL certificate are ready. Replayed callbacks must be safe and idempotent.

## 13. Deployment log template

Keep one release record per staging/prod deployment with:

- environment and hostname set;
- GitHub repository, release tag, full commit SHA, and cPanel checked-out branch;
- deploy operator and approver;
- start/end time and deployment outcome;
- migrations applied and backup identifier;
- configuration, callback, and cron changes;
- pre- and post-deployment smoke-test results;
- wallet/ledger, campaign-funding, payment, and referral reconciliation results where applicable;
- rollback readiness and any known issues.

Do not put secrets, user exports, bank details, or full payment payloads in this record.

## 14. Go-live checklist

Proceed only when every critical item is confirmed:

- The private GitHub repo is protected and the production SHA is reviewed and tagged.
- The cPanel private deploy key is read-only and revocable.
- The repository clone is outside webroots; secrets and storage are private.
- The `.cpanel.yml` deployment path has passed staging tests and excludes unintended files.
- All five domains, SSL certificates, PHP runtime settings, and document roots are verified, including the apex-domain `public_html` issue.
- Staging acceptance tests pass for authentication, role separation, task lifecycle, payments, callbacks, uploads, and cron behavior.
- Database backup and restore have been demonstrated.
- V1 migration dry run meets the zero-unexplained-difference condition.
- Afrstar source-IP, callbacks, and real low-value payment tests are approved for the production window.
- Password-reset communications, maintenance messaging, support monitoring, and rollback authority are ready.
- Financial reconciliation and post-cutover monitoring owners are present.

If the apex-domain document root, payment callback path, database restore, or financial reconciliation is unresolved, treat it as a release blocker.

## References

[1]: https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-deployment/ "Guide to Git Set Up Deployment"
[2]: https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/ "Guide to Git Deployment"
[3]: https://support.cpanel.net/hc/en-us/articles/33362458128919-Changing-the-document-root-for-the-main-domain "Changing the document root for the main domain"
[4]: https://docs.cpanel.net/cpanel/software/multiphp-manager-for-cpanel/ "MultiPHP Manager for cPanel"
[5]: https://docs.cpanel.net/cpanel/domains/domains/ "Domains"
[6]: https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-access-to-private-repositories/ "Guide to Git Set Up Access to Private Repositories"
[7]: https://docs.cpanel.net/cpanel/files/git-version-control/ "Git Version Control"
[8]: https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/ "Guide to Git Deployment"
[9]: https://docs.cpanel.net/cpanel/advanced/cron-jobs/ "Cron Jobs"

# YOLOTASK V2 — Repository Architecture

**Purpose:** Define the complete planned file and directory structure for the new YOLOTASK V2 repository. This is an architecture blueprint only; it does not create application code, a GitHub repository, or a cPanel deployment.

**Deployment model:** Develop and version the application in one private GitHub monorepo. cPanel clones or pulls that repository. Each subdomain's document root points directly to its app's `public/` directory. Configuration, shared application code, migrations, cron scripts, and private storage remain outside every public document root.

## Repository tree

```text
yolotask-v2/
├── .github/
│   ├── ISSUE_TEMPLATE/
│   │   ├── bug-report.md
│   │   └── feature-request.md
│   ├── workflows/
│   │   └── checks.yml                 # Automated checks that do not require production secrets
│   ├── pull_request_template.md
│   └── CODEOWNERS                     # Optional ownership rules for sensitive areas
├── .gitignore
├── .editorconfig
├── .gitattributes
├── .cpanel.yml                        # cPanel Git deployment tasks; no secrets
├── README.md
├── CHANGELOG.md
├── config/
│   ├── README.md                      # Setup instructions; never contains real credentials
│   ├── app.example.php                # URL, environment, cookie and app settings
│   ├── database.example.php           # MySQL/MariaDB connection settings
│   ├── payments.example.php           # Afrstar and Paystack configuration keys
│   ├── notifications.example.php      # Resend and OneSignal configuration keys
│   ├── virtual-numbers.example.php   # FiveSim and SMSPool configuration keys
│   └── storage.example.php            # Private storage paths and limits
├── database/
│   ├── README.md                      # Migration, seed and verification runbook
│   ├── migrations/
│   │   ├── 0001_users_sessions_security.sql
│   │   ├── 0002_settings_catalog_interests.sql
│   │   ├── 0003_wallets_ledger.sql
│   │   ├── 0004_payments_banks_withdrawals.sql
│   │   ├── 0005_campaigns_funding_links.sql
│   │   ├── 0006_tasks_submissions_proofs_appeals.sql
│   │   ├── 0007_growth_referrals_bonuses_levels.sql
│   │   ├── 0008_notifications_announcements.sql
│   │   ├── 0009_virtual_number_orders.sql
│   │   └── 0010_indexes_constraints_finalization.sql
│   ├── seeds/
│   │   ├── 001_platforms_services_interests.sql
│   │   ├── 002_default_settings_levels.sql
│   │   └── 003_first_admin.instruction.md
│   └── verify/
│       ├── wallet_ledger_reconciliation.sql
│       ├── campaign_funding_reconciliation.sql
│       ├── payment_order_reconciliation.sql
│       ├── referral_award_reconciliation.sql
│       └── pre_release_checks.sql
├── docs/
│   ├── README.md
│   ├── architecture.md                 # Boundaries, request flow and shared-module contracts
│   ├── feature-parity.md               # V1 feature inventory and V2 decisions
│   ├── subdomains-and-cpanel.md         # Document roots, DNS, SSL and deployment layout
│   ├── configuration-and-secrets.md    # Per-environment setup; no real secrets
│   ├── database-and-migrations.md
│   ├── ledger-and-money.md              # Money representation, transactions and invariants
│   ├── payments.md                      # Afrstar collection/payout and Paystack name lookup
│   ├── callbacks-and-reconciliation.md
│   ├── authentication-and-authorization.md
│   ├── file-uploads-and-proof-storage.md
│   ├── cron-and-operations.md
│   ├── testing-and-release.md
│   ├── data-migration-v1-to-v2.md
│   ├── cutover-and-rollback.md
│   ├── security-review-checklist.md
│   └── decisions/
│       ├── README.md                    # Decision log index
│       └── open-product-decisions.md    # Unresolved roadmap questions and owners
├── shared/
│   ├── bootstrap.php                   # Loads trusted config, autoloading and core services
│   ├── autoload.php                    # Small project autoloader; no public file exposure
│   ├── routes.php                      # Shared health and common route definitions, if needed
│   ├── lib/
│   │   ├── Core/
│   │   │   ├── Database.php            # PDO connections and transaction helpers
│   │   │   ├── Settings.php            # Cached database settings
│   │   │   ├── Response.php            # HTML, JSON, redirect and error responses
│   │   │   ├── Logger.php
│   │   │   ├── Validator.php
│   │   │   ├── Clock.php               # Testable time provider
│   │   │   └── IdGenerator.php
│   │   ├── Security/
│   │   │   ├── Auth.php
│   │   │   ├── Authorization.php       # Role, ownership and state checks
│   │   │   ├── Session.php
│   │   │   ├── Csrf.php
│   │   │   ├── RateLimiter.php
│   │   │   ├── Audit.php
│   │   │   ├── Crypto.php               # Encryption for sensitive bank details
│   │   │   ├── PasswordReset.php
│   │   │   └── Input.php
│   │   ├── Money/
│   │   │   ├── Money.php                # Decimal-string arithmetic; no floating point
│   │   │   ├── Fees.php
│   │   │   ├── Ledger.php               # Sole component permitted to post balance changes
│   │   │   └── Wallet.php
│   │   ├── Payments/
│   │   │   ├── AfrstarClient.php
│   │   │   ├── PaystackClient.php       # Account-name lookup only
│   │   │   ├── BankCodes.php
│   │   │   ├── PaymentOrders.php
│   │   │   ├── CallbackVerifier.php
│   │   │   ├── CallbackProcessor.php
│   │   │   ├── PaymentReview.php        # Holds unmatched or suspicious payments
│   │   │   └── Withdrawals.php
│   │   ├── Campaigns/
│   │   │   ├── Campaigns.php
│   │   │   ├── CampaignMembers.php
│   │   │   ├── CampaignLinks.php         # Exact and normalized duplicate checks
│   │   │   ├── Instructions.php          # Versioned instruction handling
│   │   │   └── Pricing.php               # Price guidance and minimum rules
│   │   ├── Tasks/
│   │   │   ├── Tasks.php                 # Claim, reserve and expire
│   │   │   ├── Submissions.php
│   │   │   ├── Proofs.php                 # Metadata and private file references
│   │   │   ├── FakeProofGuard.php
│   │   │   ├── Appeals.php
│   │   │   ├── StaleQueue.php
│   │   │   └── Approvals.php
│   │   ├── Growth/
│   │   │   ├── Levels.php
│   │   │   ├── Reputation.php
│   │   │   ├── Leaderboard.php
│   │   │   ├── Referrals.php
│   │   │   ├── Checkin.php
│   │   │   └── Bonuses.php
│   │   ├── VirtualNumbers/
│   │   │   ├── VirtualNumbers.php
│   │   │   └── Providers/
│   │   │       ├── ProviderInterface.php
│   │   │       ├── FiveSimProvider.php
│   │   │       └── SmsPoolProvider.php
│   │   ├── Messaging/
│   │   │   ├── Notifications.php
│   │   │   ├── Mailer.php
│   │   │   └── Push.php
│   │   └── Support/
│   │       ├── Pagination.php
│   │       ├── Uploads.php
│   │       └── FeatureFlags.php
│   ├── views/
│   │   ├── layouts/
│   │   │   ├── public.php
│   │   │   ├── authenticated.php
│   │   │   └── email.php
│   │   ├── components/
│   │   │   ├── alert.php
│   │   │   ├── pagination.php
│   │   │   ├── form-errors.php
│   │   │   └── flash-message.php
│   │   └── errors/
│   │       ├── 403.php
│   │       ├── 404.php
│   │       └── 500.php
├── apps/
│   ├── main/
│   │   ├── public/                      # yolotask.com document root
│   │   │   ├── index.php                # Front controller
│   │   │   ├── .htaccess                # Deny directory listing; safe rewrites and headers
│   │   │   ├── robots.txt
│   │   │   ├── sitemap.xml
│   │   │   ├── assets/
│   │   │   │   ├── css/
│   │   │   │   │   ├── app.css
│   │   │   │   │   └── auth.css
│   │   │   │   ├── js/
│   │   │   │   │   ├── app.js
│   │   │   │   │   └── forms.js
│   │   │   │   ├── images/
│   │   │   │   └── icons/
│   │   │   └── manifest.json
│   │   ├── routes.php
│   │   ├── pages/
│   │   │   ├── home.php
│   │   │   ├── about.php
│   │   │   ├── how-it-works.php
│   │   │   ├── faq.php
│   │   │   ├── terms.php
│   │   │   ├── privacy.php
│   │   │   ├── refunds.php
│   │   │   ├── maintenance.php
│   │   │   ├── offline.php
│   │   │   ├── forgot-password.php
│   │   │   └── reset-password.php
│   │   └── actions/
│   │       ├── auth/
│   │       │   ├── forgot-password.php
│   │       │   └── reset-password.php
│   │       └── health.php
│   ├── earner/
│   │   ├── public/                      # earner.yolotask.com document root
│   │   │   ├── index.php
│   │   │   ├── .htaccess
│   │   │   ├── manifest.json
│   │   │   ├── service-worker.js
│   │   │   └── assets/
│   │   │       ├── css/
│   │   │       │   ├── app.css
│   │   │       │   ├── dashboard.css
│   │   │       │   └── forms.css
│   │   │       ├── js/
│   │   │       │   ├── app.js
│   │   │       │   ├── navigation.js
│   │   │       │   └── fetch-updates.js
│   │   │       ├── images/
│   │   │       └── icons/
│   │   ├── routes.php
│   │   ├── pages/
│   │   │   ├── register.php
│   │   │   ├── login.php
│   │   │   ├── dashboard.php
│   │   │   ├── tasks/
│   │   │   │   ├── index.php
│   │   │   │   └── show.php
│   │   │   ├── submissions/
│   │   │   │   ├── index.php
│   │   │   │   └── show.php
│   │   │   ├── wallet/
│   │   │   │   ├── index.php
│   │   │   │   ├── deposit.php
│   │   │   │   ├── withdraw.php
│   │   │   │   ├── history.php
│   │   │   │   └── receipt.php
│   │   │   ├── bank-accounts/
│   │   │   │   └── index.php
│   │   │   ├── leaderboard.php
│   │   │   ├── levels.php
│   │   │   ├── referrals.php
│   │   │   ├── checkin.php
│   │   │   ├── virtual-numbers.php
│   │   │   ├── marketplace-coming-soon.php
│   │   │   ├── notifications.php
│   │   │   ├── profile.php
│   │   │   └── security.php
│   │   └── actions/
│   │       ├── auth/
│   │       │   ├── register.php
│   │       │   ├── login.php
│   │       │   ├── logout.php
│   │       │   └── password-reset.php
│   │       ├── tasks/
│   │       │   ├── claim.php
│   │       │   └── release.php
│   │       ├── submissions/
│   │       │   ├── submit.php
│   │       │   ├── resubmit.php
│   │       │   └── appeal.php
│   │       ├── wallet/
│   │       │   ├── start-deposit.php
│   │       │   ├── request-withdrawal.php
│   │       │   └── add-bank-account.php
│   │       ├── growth/
│   │       │   ├── checkin.php
│   │       │   └── referral-link.php
│   │       ├── virtual-numbers/
│   │       │   ├── purchase.php
│   │       │   ├── poll.php
│   │       │   └── cancel.php
│   │       ├── profile/
│   │       │   ├── update.php
│   │       │   └── change-password.php
│   │       └── notifications/
│   │           └── mark-read.php
│   ├── advertiser/
│   │   ├── public/                      # advertiser.yolotask.com document root
│   │   │   ├── index.php
│   │   │   ├── .htaccess
│   │   │   ├── manifest.json
│   │   │   └── assets/
│   │   │       ├── css/
│   │   │       ├── js/
│   │   │       ├── images/
│   │   │       └── icons/
│   │   ├── routes.php
│   │   ├── pages/
│   │   │   ├── register.php
│   │   │   ├── login.php
│   │   │   ├── dashboard.php
│   │   │   ├── campaigns/
│   │   │   │   ├── index.php
│   │   │   │   ├── create.php
│   │   │   │   ├── show.php
│   │   │   │   └── edit.php
│   │   │   ├── submissions/
│   │   │   │   ├── index.php
│   │   │   │   └── show.php
│   │   │   ├── wallet/
│   │   │   │   ├── index.php
│   │   │   │   ├── deposit.php
│   │   │   │   ├── history.php
│   │   │   │   └── receipt.php
│   │   │   ├── analytics.php
│   │   │   ├── referrals.php
│   │   │   ├── virtual-numbers.php
│   │   │   ├── marketplace-coming-soon.php
│   │   │   ├── notifications.php
│   │   │   ├── profile.php
│   │   │   └── business-settings.php
│   │   └── actions/
│   │       ├── auth/
│   │       ├── campaigns/
│   │       │   ├── create.php
│   │       │   ├── update.php
│   │       │   ├── pause.php
│   │       │   ├── resume.php
│   │       │   └── update-members.php
│   │       ├── submissions/
│   │       │   ├── approve.php
│   │       │   └── reject.php
│   │       ├── wallet/
│   │       │   └── start-deposit.php
│   │       ├── profile/
│   │       │   └── update-business-settings.php
│   │       └── notifications/
│   ├── reviewer/
│   │   ├── public/                      # reviewer.yolotask.com document root
│   │   │   ├── index.php
│   │   │   ├── .htaccess
│   │   │   └── assets/
│   │   │       ├── css/
│   │   │       ├── js/
│   │   │       └── icons/
│   │   ├── routes.php
│   │   ├── pages/
│   │   │   ├── login.php
│   │   │   ├── dashboard.php
│   │   │   ├── stale-queue.php
│   │   │   ├── decision-history.php
│   │   │   └── profile.php
│   │   └── actions/
│   │       ├── auth/
│   │       ├── queue/
│   │       │   ├── claim.php
│   │       │   └── release.php
│   │       └── decisions/
│   │           ├── approve.php
│   │           └── reject.php
│   └── admin/
│       ├── public/                      # admin.yolotask.com document root
│       │   ├── index.php
│       │   ├── .htaccess
│       │   └── assets/
│       │       ├── css/
│       │       ├── js/
│       │       └── icons/
│       ├── routes.php
│       ├── pages/
│       │   ├── login.php
│       │   ├── dashboard.php
│       │   ├── users/
│       │   ├── staff/
│       │   ├── catalog/
│       │   ├── campaigns/
│       │   ├── submissions/
│       │   ├── reports/
│       │   ├── held-payments/
│       │   ├── withdrawals/
│       │   ├── payments/
│       │   ├── wallet-adjustments/
│       │   ├── referrals-and-bonuses/
│       │   ├── levels-and-checkins/
│       │   ├── leaderboard/
│       │   ├── announcements/
│       │   ├── virtual-numbers/
│       │   ├── settings/
│       │   ├── audit-logs.php
│       │   └── maintenance.php
│       └── actions/
│           ├── auth/
│           ├── users/
│           ├── staff/
│           ├── catalog/
│           ├── campaigns/
│           ├── held-payments/
│           ├── withdrawals/
│           ├── wallet-adjustments/
│           ├── bonuses/
│           ├── announcements/
│           ├── virtual-numbers/
│           └── settings/
├── hooks/
│   ├── README.md                        # Callback URL and deployment notes
│   ├── afrstar_collection.php           # Public callback endpoint; strict verification
│   └── afrstar_payout.php               # Public payout callback endpoint; strict verification
├── cron/
│   ├── README.md                        # cPanel cron command schedule and runbook
│   ├── every-minute.php                 # Number polling/refunds and expired reservations
│   ├── every-five-minutes.php           # Callback retries and stale Afrstar orders
│   ├── every-fifteen-minutes.php        # Stale submissions and eligible auto-approval
│   ├── hourly.php                       # Reward releases, campaign completion, leaderboard
│   ├── daily.php                        # Reconciliation, cleanup and database backup
│   └── close-leaderboard-period.php     # Weekly/monthly period close
├── storage/                             # Private runtime directory; never committed
│   ├── proofs/                          # Uploaded proof files, outside all public roots
│   ├── logs/
│   ├── cache/
│   ├── exports/
│   └── backups/
└── tests/
    ├── README.md
    ├── bootstrap.php
    ├── Unit/
    │   ├── Money/
    │   ├── Fees/
    │   ├── Authorization/
    │   ├── CampaignLinks/
    │   └── CallbackMatching/
    ├── Integration/
    │   ├── DatabaseTransactions/
    │   ├── LedgerPosting/
    │   ├── PaymentCallbacks/
    │   ├── CampaignLifecycle/
    │   ├── TaskSubmissions/
    │   └── WithdrawalLifecycle/
    ├── Security/
    │   ├── CsrfChecks/
    │   ├── RoleAndOwnershipChecks/
    │   ├── RateLimits/
    │   ├── UploadValidation/
    │   └── SessionIsolation/
    └── Migration/
        ├── fixtures/
        ├── dry-run-report.md
        └── reconciliation-cases.md
```

## Subdomain and document-root mapping

| Hostname | cPanel document root | Application boundary |
|---|---|---|
| `yolotask.com` | `apps/main/public` | Public site, password-reset entry points, static public resources |
| `earner.yolotask.com` | `apps/earner/public` | Earner session and earner-only routes |
| `advertiser.yolotask.com` | `apps/advertiser/public` | Advertiser session and advertiser-only routes |
| `reviewer.yolotask.com` | `apps/reviewer/public` | Staff-created reviewer accounts and review queue |
| `admin.yolotask.com` | `apps/admin/public` | Staff-created admin accounts and privileged operations |

All five apps use the same private `config/`, `shared/`, database, and private `storage/`. Each app has its own front controller and route map. The front controller dispatches requests to app pages or action handlers. A request must pass server-side authentication, role, ownership, status, and CSRF checks as appropriate. Session cookies are scoped to the relevant subdomain; a correct URL alone never grants a role.

The payment callback endpoints are public URLs, but their implementation files must still load shared code from outside the document root and validate provider authenticity, merchant, order, amount, and idempotency before applying any financial effect. Any path or cPanel rewrite used to publish them must not expose the repository root.

## Architecture rules captured by this layout

- Only `shared/lib/Money/Ledger.php` may post balance changes. Wallet and campaign operations call it inside database transactions with appropriate row locks.
- Store monetary values as `DECIMAL(19,2)` in the database and decimal strings in application code; do not use floating-point arithmetic for money.
- Use PDO prepared statements for database access. Keep authorization and state-transition checks in shared application services, not only in page handlers.
- Keep secrets out of Git. Commit example configuration only; provision real configuration through private cPanel files or protected environment configuration.
- Keep proofs, logs, backups, and generated exports outside all public roots. Record proof hashes and serve permitted files through access-controlled, short-lived links.
- Process callbacks idempotently. Unmatched or suspicious payments go to review and are never automatically credited.
- Audit-log administrative, reviewer, and financial state changes. Rate-limit sensitive account, claim, submission, and virtual-number actions.
- Treat the `database/migrations/` sequence as immutable after release. Prefer additive migrations and take a database backup before applying migrations in production.
- Build and test from the GitHub repository. cPanel is a deployment target that pulls a reviewed revision; it is not the source-of-truth development workspace.

## GitHub-to-cPanel release path

1. Work in a development branch and review changes through GitHub before merging to the production branch.
2. Run automated checks that are safe without production credentials, plus the relevant test suite against a non-production database.
3. Pull the selected revision to the staging cPanel copy. Confirm its subdomain document roots, private configuration, storage permissions, cron entries, callback URLs, and database connection.
4. Back up the target database before applying any new numbered migration. Run migration verification and ledger/payment reconciliation checks.
5. Promote the reviewed release to production and have cPanel pull that tagged/reviewed revision. Never put provider keys or database passwords into the repository or deployment file.
6. Keep the prior release tag and v1 read-only through the agreed rollback window. Roll back application code only in a way compatible with the database schema; do not assume a code checkout reverses a migration.

## Items to settle before implementation

The roadmap's open decisions should be recorded and approved before their behavior is built: reviewer staffing/compensation; who may order virtual numbers; the qualified-referral rule; level thresholds, perks, and check-in scaling; stale-submission limit, reward hold window, and resubmission cap; the path after an advertiser upholds a rejection; Afrstar status-query support and source-IP requirements; and final confirmation of fee treatment. The V1 feature-parity inventory and source-data mapping should also be completed before the migration phases begin.

## Basis

This architecture translates the supplied **YOLOTASK V2 — File Structure and Roadmap** into a repository layout and includes the clarified workflow that the new GitHub repository is the source of truth and cPanel clones/pulls it for deployment. The existing public YOLOTASK repo was inspected as the V1 reference: it is currently organized as a JavaScript/Vercel/Supabase application, so this tree describes the proposed new PHP repository rather than a restructuring of that codebase.

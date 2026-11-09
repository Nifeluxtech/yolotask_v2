# YOLOTASK tasks, bundles and optional account activation (migration 0019)

## Why

Few advertisers means few tasks. YOLOTASK therefore provides tasks itself
("YOLOTASK tasks", `platform tasks` in the code): social accounts with several
actions each (follow the profile, like post A, comment on post B …). Earners
see them in the **same task feed** as advertisers' tasks, with no special
label. Separately, an admin can require a one-time **account activation**
payment before earners can access any task. It ships **OFF**.

## YOLOTASK tasks

- **Bundle** (`platform_task_bundles`): one social account = platform, account
  name, optional profile link, a unique `bundle_key`, status `live` / `paused`.
- **Task**: a `campaigns` row with `is_platform = 1` and `bundle_id`, owned by
  the closed system account **"YOLOTASK"** (`platform_tasks.owner_user_id`,
  email `platform-tasks@system.yolotask.invalid`, cannot sign in). Each task
  has its own link, task type, instructions, reward and proof; there is no
  combined bundle reward. One task per bundle + task type + normalised link
  (unique key `uq_campaigns_bundle_task`).
- **Flow**: the normal one (reserve, submit proof, approve / reject, appeals,
  auto-approve). Proofs go straight to the reviewer queue (no advertiser);
  reviewers and admins see a subtle **YOLOTASK** source label, earners never do.
- **No slot limit, once per earner**; the level's held-task limit applies.
- **Reward** = the task type's normal earner reward ×
  `platform_tasks.reward_percent` (default **50** = half), rounded down to the
  kobo, minimum 1 kobo. Price = reward (no margin, no escrow, no budget). The
  platform pays: **Dr `system:platform_task_payouts` / Cr earner wallet** (or
  `system:reward_hold`). An admin reversal of an overturned appeal credits it
  back. Changing the percent or a task type's catalog reward reprices every
  YOLOTASK task; slots already started keep the price they were reserved at.
- **Counts toward** referral milestones, leaderboards and earner levels like
  any approved task (they are ordinary approved submissions).
- **Feed order**: advertisers' tasks first (newest approval first), then
  YOLOTASK tasks bundle by bundle. A page holds 20 units: an advertiser task or
  one bundle. A bundle with two or more tasks left for the earner is one card
  "Account · Platform · N tasks" (total reward) that expands into its tasks; a
  bundle with one task left shows that task as a normal row.
- A disabled bundle hides all its tasks; a disabled task is hidden; a held
  slot on either can still be submitted. A removed task is `ended` (history
  kept, held slots released). The admin "Add tasks" form brings a removed task
  back; a bulk import never does.
- YOLOTASK tasks never block an advertiser campaign for the same link, and an
  earner who did a link + task type in one place does not see it again
  elsewhere (existing rule).

### Admin → Tasks → YOLOTASK tasks (`platform-tasks.php`)

Overview (live tasks, bundles, completed, rewards paid), bundle list (filter,
search, completion counts, bulk enable / disable), bundle page (edit name and
profile link, enable / disable, **add tasks**: pick a task type and paste
several links at once, optional instructions; per-task enable / disable /
remove / edit with a new instructions version; completion counts), new bundle
form, **bulk import**, reward percent, live-by-category table.

### Bulk import

CSV (UTF-8) whose first line is the header

    bundle_key,platform,account_name,task_type,url,instructions

Columns may be in any order; `instructions` may be empty (standard wording per
task type). Rows with the same `bundle_key` form one bundle; each row is one
task. Platform and task type match slug or name, with aliases (`twitter` → X,
`likes` → like, `retweet` / `repost` → share, `view` → watch …). Each link
must be on the platform's domains (Threads: threads.net / threads.com; X:
x.com / twitter.com). Bad rows are listed by line and skipped; a bundle is only
made when at least one of its rows is usable. Idempotent: matched on
bundle_key + task type + normalised link, so re-running adds nothing (and
never revives a task an admin removed). An existing bundle is reused and keeps
its name and status.

- Admin page: paste or upload (≤ 2 MB, ≤ 5,000 rows), optional dry run.
- CLI: `php scripts/import-platform-tasks.php --file=<csv> [--dry-run] --config-dir=~/yolotask-v2-live/config-private`
  (exit 0 done, 1 could not run, 2 every row failed).
- Seed: `database/seeds/platform_tasks_seed.csv` (1,000 tasks in 241 bundles:
  Instagram, YouTube, X, TikTok, Facebook, Threads).

## Account activation (`Account\Activation`)

- Settings: `activation.required` (**0 = OFF**, seeded OFF by the migration),
  `activation.fee_kobo` (default **50000 = ₦500**, ₦100–₦100,000). Admin →
  Tasks → Account activation (`activation.php`).
- **OFF**: every earner sees and can do every task; nothing links to or
  mentions activation (`activate.php` just says no activation is needed).
- **ON**: an earner without an activation sees no task at all (advertisers' or
  YOLOTASK's): the feed, the dashboard and any task page show **"Activate your
  account with ₦500.00 to access tasks."** with a link to `activate.php`, and
  starting a task is refused. **In-flight work is not stranded**: a slot held
  before the switch can still be submitted (it stays under *In progress*),
  proofs, resubmissions and appeals carry on.
- Activated earners stay activated (switching OFF and ON again keeps them).
- Payment: bank transfer through an AfrStar collection order, reference
  `YTA…` (never forwarded by the callback hub). Exact amount → Dr
  `system:afrstar_clearing` / Cr `system:activation_revenue` (key
  `activation:<order>`), the earner is activated, inbox + email. Never touches
  the wallet. Replays idempotent. A different amount → payment review queue
  (admin **Activate** with the paid amount, or Reject). A second payment by an
  activated earner → revenue + review item "Second activation payment"
  (refund outside the app, then dismiss). Waiting orders expire like top-ups
  (every-five-minutes cron). AfrStar has no status API: an admin can **verify**
  a payment found in the AfrStar portal (same ledger key, so a late callback
  is a duplicate).

## Reconciliation

`Account\Activation::reconcile` (part of `Ledger::reconcile`, daily cron):
activation revenue = paid activation payments, each with its posting, every
activation has a paid payment, YOLOTASK task payouts = rewards of approved
YOLOTASK tasks, no YOLOTASK task holds escrow or a margin. Read-only SQL:
`database/verify/platform_tasks_reconciliation.sql` (also owner account,
bundle consistency and the import key).

## Deploy

1. Upload the release (the release script runs migration
   `0019_platform_tasks_activation.sql`; activation stays OFF).
2. `php scripts/import-platform-tasks.php --file=database/seeds/platform_tasks_seed.csv --dry-run --config-dir=~/yolotask-v2-live/config-private`,
   then the same without `--dry-run`, run from the release directory.
3. No new cron lines.

# Jobs (migration 0023)

Jobs are multi-step tasks that take longer than a normal task. There are no
preset job types or platforms: the advertiser writes everything. Earners do the
steps in order, send proof for each step, and submit the whole job once; the
advertiser reviews every step on one page.

## Who does what

| Role | Can |
|---|---|
| Advertiser | Jobs tab (`jobs.php`): post a job (`job-new.php`), manage it (`job.php`: pause / resume, add slots, close), review submissions (`job-review.php`: approve, send one step back, reject). |
| Earner | Jobs tab (`jobs.php`): open jobs with an Online / Offline badge (offline jobs show their location) and **My jobs**. Job page (`job.php`): read every step, Start, add proof step by step, submit, fix a sent-back step and resubmit, give up, appeal a rejection. |
| Reviewer | **Job appeals** tab (`job-appeals.php`, `job-appeal.php`): claim, uphold or overturn, same claim rules as task appeals. |
| Admin | Admin → Tasks → **Jobs** (`jobs.php`): list / filter, a job's attempts and escrow, every step's proof of an attempt, pause (advertiser cannot resume) / unpause, remove with refund, job appeals (admin decision is final), settings. |

Navigation: earner and advertiser headers and bottom docks gain **Jobs**
(briefcase icon) after Tasks / Campaigns; Marketplace and everything else stay.
Reviewer and admin reach Jobs through their tabs.

## A job

- Name (3–120), overall instructions (10–5,000), optional http(s) link (shown
  to earners once they start), mode **online** or **offline**; offline jobs
  need a location text (state / city / address area, 3–160), shown to earners
  on the list and the job page.
- **Steps**: 2 to `jobs.max_steps` (default 20). Each step: title (2–120),
  instructions (5–3,000) and one or more required proof kinds: screenshot /
  image, text answer, link, file. Every ticked kind is required. Steps are
  fixed once published.
- **Pay per earner** (at least `jobs.min_pay_kobo`, default ₦200; at most
  ₦1,000,000). The earner gets `jobs.earner_percent` (default 90 %) rounded
  down to the kobo; the platform keeps the rest. The split is stored on the job
  (and on each attempt) when published, so later setting changes do not touch it.
- **Slots** (1–10,000), **time limit** to finish once started (between
  `jobs.time_limit_min_hours` and `jobs.time_limit_max_hours`, default 1 hour
  to 14 days; entered in hours or days), **review window** (the task
  auto-approve bounds `tasks.auto_approve_min_hours` / `_max_hours`; the form
  defaults to the advertiser's campaign auto-approve setting).

No admin approval: **publishing** locks pay × slots from the advertiser's
wallet (available balance, like campaign funding) and the job is live at once.
`jobs.enabled = 0` hides the earner list and stops new jobs and new starts;
work already started, reviews, appeals and cron keep running.

Statuses: `live` ⇄ `paused` (advertiser; an admin pause sets `admin_paused`
and only an admin can lift it), then `closed` (advertiser), `removed` (admin)
or `completed` (every funded slot approved and nothing active).

## Doing a job (earner)

1. **Start** (needs a verified email and, while `activation.required = 1`, an
   activated account): takes a free slot and starts the timer
   (`deadline_at = now + time limit`). An earner can do a given job **once**
   (one attempt row per job and earner, whatever its outcome) and may have
   `jobs.max_active_per_earner` (default 1) jobs in progress or sent back at once.
2. **Steps**: step N's form opens once steps 1..N−1 have every required proof.
   Earlier steps stay editable until the final submit. Images go through the
   same checks as task proofs (JPEG/PNG/WebP, `tasks.proof_max_bytes`,
   re-encoded, a screenshot already used on another task or job is refused);
   files: PDF, Office, TXT, CSV, ZIP, images, audio, video up to 10 MB, and
   HTML / PHP / executables are refused by content. Text up to 5,000
   characters; links must be http(s).
3. **Submit for review** when every step is done, before the deadline.
   `review_due_at = now + review window`.
4. **Give up** (in progress or sent back): the slot goes back, nothing is paid,
   the job cannot be taken again.
5. **Timer expiry** (cron every 5 minutes): an attempt still in progress at its
   deadline is `expired`; slot back, nothing paid, earner notified.

## Review (advertiser)

One page shows every step's instructions and proof (images inline, files as a
download, text and links).

- **Approve** → earner paid (below).
- **Send back** one step with a note (3–500). At most
  `jobs.send_back_max_rounds` (default 2) per attempt. The earner gets
  `jobs.send_back_fix_hours` (default 48) to change that step (only that step
  is editable) and resubmit; the review window starts again. Missing the fix
  deadline expires the attempt (slot back, nothing paid).
- **Reject** with a reason (3–255): the slot returns to the job at once (or is
  refunded when the job is closed); the earner may appeal.
- **No action** within the review window → **auto-approved** (cron every 15
  minutes), earner notified.

## Appeals

A rejected attempt can be appealed once, within `appeals.window_hours` (72) of
the rejection, with a reason (10–1,000). Appeals land in the reviewer portal's
**Job appeals** tab (next to the task Appeals tab), use the same 30-minute
claims (`review_claims.item_type = 'job_appeal'`), and every decision is logged
in `reviewer_actions` (`job_appeal_upheld` / `job_appeal_overturned`,
`job_attempt_id` set, `submission_id` NULL) so it shows in My decisions.
Reviewers can open job proofs only for appealed attempts. Admins can decide
any open job appeal from Admin → Jobs → Appeals.

- **Uphold**: nothing moves. The slot already went back to the job when it was
  rejected (or was refunded if the job is closed).
- **Overturn**: the earner is paid as on approval. The money comes from a free
  slot of the job when it is live/paused and has one. Otherwise one slot is
  added and funded first: from the advertiser's wallet, and whatever the wallet
  cannot cover is paid by YOLOTASK (`system:jobs_appeal_payouts`), recorded on
  the appeal (`wallet_kobo`, `platform_kobo`) and shown on the admin page.

## Money

All amounts are integer kobo; every posting has an idempotency key.

| Event | Ledger | Key |
|---|---|---|
| Publish / add slots | Dr advertiser wallet available / Cr `system:jobs_escrow` (pay × slots) | `job-fund:<job>:<seq>` |
| Approve (advertiser, auto, admin) | Dr `system:jobs_escrow` (pay) / Cr earner available — or `system:reward_hold` when `tasks.reward_hold_hours` > 0 — (earner share) / Cr `system:jobs_revenue` (platform share) | `job-approve:<attempt>` |
| Held reward release (hourly) | Dr `system:reward_hold` / Cr earner available | `job-release:<attempt>` |
| Close / remove / slot freed on a closed job | Dr `system:jobs_escrow` / Cr advertiser wallet available | `job-refund:<job>:<seq>` |
| Overturn with no free slot | Dr advertiser wallet (what it has) + Dr `system:jobs_appeal_payouts` (rest) / Cr `system:jobs_escrow` | `job-appeal-fund:<appeal>` |
| Overturn payout | as approve | `job-appeal-approve:<appeal>` |

Slots: `slots` = slots currently funded; `active_count` = attempts in
progress, submitted or sent back; free = slots − approved − active. On close /
remove every slot that is neither approved nor active is refunded at once;
each active attempt that later ends without approval (reject, expiry, give up)
refunds its slot. Removing a job also cancels attempts still in progress (not
yet submitted); submitted and sent-back work stays until decided or expired.

**Reconciliation** (`Jobs\Jobs::reconcile`, part of `Money\Ledger::reconcile`
and of the hourly cron check):

- per job: funded − spent − refunded = pay × (slots − approved), and spent =
  pay × approved;
- job counters match the attempts; a closed job holds only approved + active slots;
- `system:jobs_escrow` = Σ (funded − spent − refunded) over all jobs
  (escrow equals locked, unpaid slots);
- `system:jobs_revenue` = Σ platform share of approved attempts;
- `system:jobs_appeal_payouts` = Σ `platform_kobo` of overturned job appeals;
- `system:reward_hold` = held task rewards **+ held job rewards**;
- every approved attempt has its `job_reward` posting.

## Notifications (category `jobs`, plus `appeals`)

- Advertiser: new submission, fixed submission resubmitted (inbox + email).
- Earner: approved, auto-approved (inbox), step sent back, rejected (inbox +
  email), time ran out, job removed by YOLOTASK (inbox).
- Appeals (category `appeals`): appeal received; outcome to the earner
  (overturned / upheld) and, on overturn, to the advertiser.

## Proof storage

`runtime/job-proofs/YYYY/MM/<random>.<ext>` (0600, outside every document
root), rows in `job_files` with the upload hash. Served only by
`job-proof.php` in the earner, advertiser, reviewer and admin portals after
`JobProofs::canView`: the uploading earner, the job's advertiser, any admin,
reviewers once the attempt is appealed. Everyone else gets 404. Images are
inline; other files are `application/octet-stream` attachments with a
sandboxed CSP. Editing a step keeps the earlier files on disk (the step points
at the newest).

## Settings (Admin → Tasks → Jobs → Settings)

| Key | Default | Range |
|---|---|---|
| `jobs.enabled` | 1 | 0 / 1 |
| `jobs.min_pay_kobo` | 20000 (₦200) | ₦1 – ₦1,000,000 |
| `jobs.earner_percent` | 90 | 50 – 100 |
| `jobs.max_steps` | 20 | 2 – 50 |
| `jobs.time_limit_min_hours` | 1 | 1 – 8760 |
| `jobs.time_limit_max_hours` | 336 (14 days) | 1 – 8760, ≥ min |
| `jobs.send_back_max_rounds` | 2 | 0 – 5 |
| `jobs.send_back_fix_hours` | 48 | 1 – 336 |
| `jobs.max_active_per_earner` | 1 | 1 – 10 |

Reused: `tasks.auto_approve_min_hours` / `_max_hours` (review window bounds),
`tasks.reward_hold_hours` (reward hold), `tasks.proof_max_bytes` (image size),
`appeals.window_hours`, `reviews.claim_minutes`, `activation.required`.

## Cron

- `every-five-minutes.php`: `Jobs\Attempts::expireDue` (time limit and fix deadline).
- `every-fifteen-minutes.php`: `Jobs\Reviews::autoApproveDue`.
- `hourly.php`: `Jobs\Reviews::releaseDue` (held rewards) and the jobs
  reconciliation alongside the campaign check (exit code 2 on mismatch).

## Deploy

Run `php scripts/migrate.php` (applies `0023_jobs.sql`) before switching the
release: the new pages, nav, cron and reconciliation read the new tables.
The migration is mostly additive: new tables, three system ledger accounts, settings
rows, new ENUM values on `review_claims` / `reviewer_actions`, and
`reviewer_actions.submission_id` becomes nullable with a new nullable
`job_attempt_id`. The previous release keeps working against it.

## Tests

- `php tests/smoke/jobs.php` — no database: lint, migration shape, split and
  rounding, validation, step order, file checks, access rules, wiring.
- `tests/Integration/Jobs/jobs_e2e.php` — MariaDB + the four portals on
  `php -S`: create → start → steps → submit → send back → resubmit → approve;
  reject → appeal → reviewer overturn; expiry and auto-approve through the real
  cron scripts; admin pause / settings / remove; close refund; ledger
  reconciliation after every step.

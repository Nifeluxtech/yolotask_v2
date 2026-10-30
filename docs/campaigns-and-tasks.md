# Campaigns, tasks and proofs (Phase 4)

## Catalog and prices
Admin → Tasks → Platforms & prices. Platforms carry the link domains a
campaign link must use. Task types carry four admin-editable amounts (kobo in
the DB): base price / base reward (standard campaigns) and premium price /
premium reward (targeted campaigns). Price − reward = platform revenue.
Campaigns snapshot price and reward at creation, so later price edits never
change an existing campaign.

Seeded (migration 0005): Instagram, TikTok, X (Twitter), Facebook, YouTube,
WhatsApp, Telegram, Threads; Follow ₦25/₦15, Like ₦15/₦10, Comment ₦40/₦25,
Share/Repost ₦40/₦25, Subscribe ₦30/₦20, Join group/channel ₦30/₦20, Watch
video ₦20/₦12. Premium = base × 1.5 rounded half-up to whole naira (Follow
₦38/₦23, Like ₦23/₦15, Comment and Share ₦60/₦38, Subscribe and Join ₦45/₦30,
Watch ₦30/₦18). Users have no gender or interest profile yet, so "targeted"
(shown to earners as Premium) means premium price, higher reward and first
place in the feed; audience filters can be added when profiles exist.

## Campaign lifecycle
`draft → pending_approval → live ⇄ paused → completed | cancelled | ended`,
or `pending_approval → rejected`.

- **Draft**: title, platform, task type, https link (must be on the
  platform's domains; no IPs, credentials or ports; normalised and hashed),
  instructions, proof requirements, quantity 1–100,000, optional end date
  (end of that day, Lagos time), standard/premium. No money moves.
- **Submit**: the full budget (price × quantity) moves from the advertiser
  wallet into the campaign's own escrow ledger account (idempotent key
  `camp-fund:<id>`). Only one open campaign may target the same normalised
  link with the same task type.
- **Admin approve** → live (advertiser emailed). **Admin reject** with reason
  → full refund (emailed).
- **Pause/resume** (advertiser). **Cancel** (advertiser or admin, from live
  or paused): active reservations are released and escrow minus the price of
  pending submissions is refunded at once; each pending submission keeps its
  price reserved until decided (rejection refunds it, approval spends it).
- **Ended**: cron closes campaigns past their end date the same way.
- **Completed**: when approved count reaches quantity; any leftover escrow
  is refunded.
- Instructions can be edited while open; each edit is a new version
  (`campaign_instructions`) and submissions record the version they used.

## Earners
- Feed: live campaigns with free slots, not their own, not already done
  (including any campaign for the same link + task type they already have a
  pending or approved submission for). Premium first.
- **Start** reserves a slot for `tasks.reservation_minutes` (default 60);
  at most `tasks.max_active_reservations` (3) at once. Slots taken =
  approved + pending + unexpired reservations, checked under the campaign
  row lock, so the last slot cannot be taken twice. The link is shown after
  starting. Release gives the slot back; expired holds stop counting at once.
- **Submit**: one submission per earner per campaign; proof screenshot
  required (JPEG/PNG/WebP ≤ 2 MB, see file-uploads-and-proof-storage.md)
  plus optional note.
- After a rejection the earner may resubmit `tasks.resubmission_cap` times
  (default 1) while the campaign is live and a slot is free.

## Decisions and money
- Approve (advertiser, admin, or automatic): Dr campaign escrow (price) /
  Cr earner wallet (reward) / Cr `system:revenue` (margin); key
  `sub-approve:<id>`. With `tasks.reward_hold_hours` > 0 the reward goes to
  `system:reward_hold` and `cron/hourly.php` releases it (key
  `sub-release:<id>`).
- Reject needs a reason (emailed to the earner); the slot returns to the pool.
- Auto-approve: pending submissions are approved after the campaign's
  auto-approve hours (advertiser setting, default 48, allowed 12–336;
  applies to new submissions on their open campaigns) by
  `cron/every-fifteen-minutes.php`.
- Stale: pending longer than `tasks.stale_hours` (24) shows under Admin →
  Submissions → Stale. Appeals and the reviewer queue are Phase 5
  (`submission_appeals` table exists, unused).

## Pages
Earner: `tasks.php`, `task.php?id=`, `submissions.php`, `submission.php?id=`,
`proof.php?id=`. Advertiser: `campaigns.php`, `campaign-new.php[?id=]`,
`campaign.php?id=`, `campaign-settings.php`, `proof.php?id=`. Admin:
`campaigns.php` (approval queue + all), `campaign.php?id=`,
`submissions.php`, `catalog.php`, `task-settings.php`, `proof.php?id=`.

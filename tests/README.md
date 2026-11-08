# Test scaffold status

The planned test directories are present. Automated tests have not yet been implemented. Do not treat an empty test suite as a passing release gate.

## Phase 3 integration test (wallets, ledger, AfrStar)

Needs PHP 8.1+ (pdo_mysql, curl, mbstring), MariaDB/MySQL with migrations applied
(`php scripts/migrate.php --config-dir=/tmp/ytcfg`) and a private config dir with
`database.local.php` and `app.local.php` (mail driver `log`, `base_urls` for the
local ports). Then:

    YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh
    YOLOTASK_CONFIG_DIR=/tmp/ytcfg python3 tests/Integration/PaymentCallbacks/phase3_test.py

The test writes fake AfrStar credentials (`TESTSN001,testak123,testsk456`) into
the config dir and points the client at `fake_afrstar.py` on port 8499.

## Crypto without ext-sodium

    python3 tests/Unit/Crypto/crypto_nosodium_test.py

Needs only the php CLI (openssl). Simulates a host without ext-sodium with
`-d disable_functions=sodium_*` and checks enc2 (AES-256-GCM) round trips,
tamper detection, legacy plain text, and enc1 reads when sodium is present.
To run the integration suites against servers without sodium:

    YT_PHP_ARGS="-d disable_functions=sodium_crypto_secretbox,sodium_crypto_secretbox_open" \
      YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh

## Phase 5 integration test (appeals, reviewer queues)

    YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh
    YOLOTASK_CONFIG_DIR=/tmp/ytcfg python3 tests/Integration/Appeals/phase5_test.py

Needs migration 0008 and Pillow; the reviewer portal runs on port 8404.

## Phase 6 integration test (referrals, levels, bonuses, notifications)

    YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh
    YOLOTASK_CONFIG_DIR=/tmp/ytcfg python3 tests/Integration/Growth/phase6_test.py

Needs migration 0009, the fake AfrStar gateway on 8499 (started by
run-servers.sh; the test writes payments.local.php + afrstar.key like the
Phase 3 test) and Pillow. Registrations wait 3 s for the sign-up form timing
guard, so the suite takes about 90 s. Sections 8b/8c cover the referral
network/device allowance (default 3) and the retirement of interests and
premium targeting (interest pages 404, no prompts or picker, every campaign
open to every earner, data left dormant).

## Phase 7 integration test (leaderboard, levels page)

    YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh
    YOLOTASK_CONFIG_DIR=/tmp/ytcfg python3 tests/Integration/Growth/leaderboard_test.py

Needs migration 0012 and MariaDB's sequence engine (`seq_1_to_N`). Builds its
own fixtures (campaign pool, approved/rejected/pending submissions, referral
payouts incl. reversed/skipped) with counts above whatever earlier suites left
in the current week/month, checks period boundaries through
`tests/Integration/Growth/lb_query.php` (fixed clock) and live rows either side
of Monday 00:00 / the 1st 00:00 WAT, then deletes its fixtures and requires a
clean reconciliation. Takes about 40 s.

## Deposit cashback integration test

    YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh
    YOLOTASK_CONFIG_DIR=/tmp/ytcfg python3 tests/Integration/Growth/cashback_test.py

Needs migration 0013 and the fake AfrStar gateway on 8499 (writes
payments.local.php + afrstar.key like the Phase 3/6 suites). Covers rounding,
0 % off, the minimum deposit, idempotency (replayed and simultaneous
callbacks), the admin review-credit path, admin credits/bonuses excluded,
no commission or cashback on cashback, referral commission alongside,
failure isolation + hourly catch-up, reversal, admin validation / CSRF /
authorization / audit, the top-up page offer and wallet history, and
reconciliation. It briefly renames `system:deposit_cashback` to force a
failure and restores it; settings are put back to 0 / 0 at the end. About
30 s.

## Withdrawal fee integration test

    YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh
    YOLOTASK_CONFIG_DIR=/tmp/ytcfg python3 tests/Integration/WithdrawalLifecycle/withdrawal_fee_test.py

Needs migration 0014 and the fake AfrStar gateway on 8499 (writes
payments.local.php + afrstar.key like the Phase 3 suite). Covers the
migration/backfill, rounding down, the server-side preview, per-level rates
(override, refused above base, capped, levels off), the rate locked at
request (admin change between preview and confirm refused; queued
withdrawals unaffected; net payout amount), pre-0014 withdrawals free, full
gross refund on reject and on a failed payout, the minimum on the gross,
CSRF/authorization/audit, display and reconciliation incl. tampering and
`database/verify/withdrawal_fee_checkin_reconciliation.sql`. Restores the
fee to 10 %, the daily count to 3 and clears level overrides. About 15 s.

## Daily check-in integration test

    YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh
    YOLOTASK_CONFIG_DIR=/tmp/ytcfg python3 tests/Integration/Growth/checkin_test.py

Needs migration 0014. The midnight WAT boundary and streaks run through
`tests/Integration/Growth/checkin_cli.php`, which pins `Core\Clock` to a
fixed time (dates in December 2026 for a dedicated test earner). Covers off
by default, the admin switch, per-level amounts (₦0 = off), once per Lagos
day (incl. five simultaneous POSTs), unverified email, streak count/reset,
ledger idempotency, CSRF/authorization/audit and reconciliation. Restores the
switch to off and every level to ₦0. About 15 s.

The Phase 3 and Phase 6 suites withdraw in two steps (preview, then confirm
with the quoted fee) and expect the default 10 % fee: AfrStar is paid the
net amount and clearing = top-ups − net payouts.

The `database/verify/*.sql` checks are run by the suites against a database
named `yt` (the files are piped into `mariadb … yt`), so name the test
database `yt`; `tests/Integration/TaskSubmissions/phase4_test.py` expects
private storage at `/tmp/ytrt` (`YT_STORAGE`), i.e. `storage.private_root`
= `/tmp/ytrt` in app.local.php, and mail logs at `/tmp/ytrt/logs/mail.log`.

Run every suite one at a time (each needs the servers; rate limits are reset
by the runner). The forwarder test needs `afrstar.key` in the config dir,
which the Phase 3/5/6 suites write, so run it after one of them. Reset `rate_limits` and
`login_attempts` between suites.

## Retire-premium test (migration 0017, scripts/retire-premium.php)

    YOLOTASK_CONFIG_DIR=/tmp/ytcfg python3 tests/Integration/Growth/retire_premium_test.py

Builds premium campaigns the way the previous release stored them (draft,
pending approval, live with work in flight, paused with every slot held,
cancelled), runs the script (dry run, real run, rerun) and checks the refund
rule, ledger postings and key, notifications, idempotency, in-flight premium
rewards, base prices for new work, the feed for earners without interests,
404 on the old interest routes and the reconciliations. Custom tasks
(migration 0016): a premium custom-task campaign keeps its own price (no
refund), a standard one is never touched, a premium campaign on the custom
platform with a listed task type is repriced, and the custom-task flow still
works after the run.

The local servers use `tests/Integration/PaymentCallbacks/router.php`, so a
path with no file behind it answers 404 like the cPanel Apache vhosts
(plain `php -S` would fall back to index.php with 200).

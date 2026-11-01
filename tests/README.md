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
network/device allowance (default 3) and interests (catalog, earner and
campaign selection, premium targeting in the feed and at reservation,
legacy and disabled interests, authorization, CSRF). Phase 4/5/6 campaign
helpers send one interest because every new campaign needs 1–3.

Run every suite one at a time (each needs the servers; rate limits are reset
by the runner). The forwarder test needs `afrstar.key` in the config dir,
which the Phase 3/5/6 suites write, so run it after one of them. Reset `rate_limits` and
`login_attempts` between suites.

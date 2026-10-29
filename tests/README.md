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

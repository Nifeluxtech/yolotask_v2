#!/usr/bin/env bash
# Starts local servers for the Phase 3 integration test:
#   8401 main (AfrStar callback hooks), 8402 earner, 8403 advertiser, 8404 reviewer, 8405 admin,
#   8499 fake AfrStar gateway.
# Usage: YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/PaymentCallbacks/run-servers.sh [stop]
# YT_PHP_ARGS adds php flags, e.g. simulate a host without ext-sodium:
#   YT_PHP_ARGS="-d disable_functions=sodium_crypto_secretbox,sodium_crypto_secretbox_open"
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
CFG="${YOLOTASK_CONFIG_DIR:-/tmp/ytcfg}"
LOGS="${YT_TEST_LOGS:-/tmp/ytrt/logs}"
mkdir -p "$LOGS"
pkill -f -- "-S 127.0.0.1:840" 2>/dev/null || true
pkill -f "fake_afrstar.py 8499" 2>/dev/null || true
[[ "${1:-}" == "stop" ]] && exit 0
sleep 0.5
start() { # port docroot
  YOLOTASK_CONFIG_DIR="$CFG" PHP_CLI_SERVER_WORKERS=4 setsid nohup php ${YT_PHP_ARGS:-} -d log_errors=1 -d error_log="$LOGS/php-$1.log" -S "127.0.0.1:$1" -t "$ROOT/$2" >"$LOGS/srv-$1.log" 2>&1 < /dev/null &
}
start 8401 apps/main/public
start 8402 apps/earner/public
start 8403 apps/advertiser/public
start 8404 apps/reviewer/public
start 8405 apps/admin/public
setsid nohup python3 "$ROOT/tests/Integration/PaymentCallbacks/fake_afrstar.py" 8499 TESTSN001 testak123 testsk456 >"$LOGS/fake-afrstar.log" 2>&1 < /dev/null &
sleep 1.5
echo "servers started (config $CFG)"

#!/usr/bin/env bash
# Starts the earner, advertiser, reviewer and admin portals on php -S for tests/Integration/Jobs/jobs_e2e.php.
# Ports: YT_JOBS_PORT_BASE (default 8600) + 2, 3, 4, 5. Logs: /tmp/yt-jobs-srv-<role>.log
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
: "${YOLOTASK_CONFIG_DIR:?set YOLOTASK_CONFIG_DIR to the private test config dir}"
BASE="${YT_JOBS_PORT_BASE:-8600}"
i=2
for role in earner advertiser reviewer admin; do
  port=$((BASE + i)); i=$((i + 1))
  YOLOTASK_CONFIG_DIR="$YOLOTASK_CONFIG_DIR" setsid nohup php -S "127.0.0.1:${port}" -t "$ROOT/apps/${role}/public" > "/tmp/yt-jobs-srv-${role}.log" 2>&1 < /dev/null &
  echo "${role}: http://127.0.0.1:${port}"
done
sleep 1

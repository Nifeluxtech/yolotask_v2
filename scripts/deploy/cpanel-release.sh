#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if ! command -v git >/dev/null 2>&1 || ! command -v tar >/dev/null 2>&1; then
  echo "ERROR: git and tar are required by the cPanel release task." >&2
  exit 1
fi
if ! git -C "$REPO_ROOT" diff --quiet || ! git -C "$REPO_ROOT" diff --cached --quiet; then
  echo "ERROR: Refusing deployment from a dirty working tree." >&2
  exit 1
fi
if [[ -n "$(git -C "$REPO_ROOT" ls-files --others --exclude-standard)" ]]; then
  echo "ERROR: Refusing deployment with untracked non-ignored files." >&2
  exit 1
fi
COMMIT="$(git -C "$REPO_ROOT" rev-parse HEAD)"
BASE="${YOLOTASK_DEPLOY_BASE:-$HOME/yolotask-v2-live}"
case "$BASE" in
  "$HOME"|"$HOME/public_html"|"$REPO_ROOT"|"/")
    echo "ERROR: Unsafe deployment base path: $BASE" >&2; exit 1;;
esac
RELEASES="$BASE/releases"
RELEASE="$RELEASES/$COMMIT"
mkdir -p "$RELEASES" "$BASE/config-private" "$BASE/runtime/proofs" "$BASE/runtime/logs" "$BASE/runtime/cache" "$BASE/runtime/exports" "$BASE/runtime/backups"
chmod 700 "$BASE/config-private" "$BASE/runtime" "$BASE/runtime/proofs" "$BASE/runtime/logs" "$BASE/runtime/cache" "$BASE/runtime/exports" "$BASE/runtime/backups" 2>/dev/null || true
if [[ ! -d "$RELEASE" ]]; then
  mkdir -p "$RELEASE"
  tar -C "$REPO_ROOT" --exclude='./.git' --exclude='./storage' --exclude='./runtime' -cf - . | tar -C "$RELEASE" -xf -
fi
# A restrictive hosting umask can create this directory as 0700. The public
# app document roots live below it, so make the release root traversable by
# the web server. Private configuration and runtime data remain outside it.
chmod 755 "$RELEASE"

if [[ ! -f "$RELEASE/apps/main/public/index.php" || ! -f "$RELEASE/apps/earner/public/index.php" ]]; then
  echo "ERROR: Expected public front controllers are missing from the release." >&2
  exit 1
fi
# Apply pending database migrations before switching traffic to the release.
PHP_BIN="$(command -v php || true)"
for candidate in /usr/local/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php; do
  if [[ -z "$PHP_BIN" && -x "$candidate" ]]; then PHP_BIN="$candidate"; fi
done
if [[ -n "$PHP_BIN" && -f "$RELEASE/scripts/migrate.php" ]]; then
  "$PHP_BIN" "$RELEASE/scripts/migrate.php" --config-dir="$BASE/config-private"
else
  echo "WARNING: php CLI not found; database migrations were not run." >&2
fi

TEMP_LINK="$BASE/current.next"
rm -f "$TEMP_LINK"
ln -s "$RELEASE" "$TEMP_LINK"
if [[ -e "$BASE/current" && ! -L "$BASE/current" ]]; then
  echo "ERROR: $BASE/current exists and is not a symlink; refusing to replace it." >&2
  rm -f "$TEMP_LINK"
  exit 1
fi
mv -Tf "$TEMP_LINK" "$BASE/current"
echo "Published YOLOTASK V2 scaffold commit $COMMIT to $BASE/current"
echo "Database migrations are applied by scripts/migrate.php during each release."

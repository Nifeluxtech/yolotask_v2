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
# cPanel's default CLI php can be older than the site's PHP, so prefer the
# EasyApache 8.x binaries and require PHP 8.1+.
PHP_BIN=""
for candidate in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php /usr/local/bin/php "$(command -v php || true)"; do
  if [[ -n "$candidate" && -x "$candidate" ]] && (cd "$BASE" && "$candidate" -d log_errors=0 -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);') >/dev/null 2>&1; then
    PHP_BIN="$candidate"; break
  fi
done
MIGRATE_LOG="$BASE/runtime/logs/deploy-migrate.log"
if [[ -f "$RELEASE/scripts/migrate.php" ]]; then
  if [[ -z "$PHP_BIN" ]]; then
    echo "ERROR: no PHP 8.1+ CLI found for migrations." | tee -a "$MIGRATE_LOG" >&2
    exit 1
  fi
  echo "[$(date -u +%FT%TZ)] $COMMIT using $PHP_BIN ($(cd "$BASE" && "$PHP_BIN" -d log_errors=0 -r 'echo PHP_VERSION;'))" >> "$MIGRATE_LOG"
  # Run from the deploy base with error_log files disabled so nothing is
  # written into the Git working copy (cPanel refuses to deploy a dirty tree).
  if ! (cd "$BASE" && "$PHP_BIN" -d log_errors=0 -d display_errors=stderr "$RELEASE/scripts/migrate.php" --config-dir="$BASE/config-private") >> "$MIGRATE_LOG" 2>&1; then
    echo "ERROR: database migration failed; see $MIGRATE_LOG. The live release was not changed." >&2
    tail -n 20 "$MIGRATE_LOG" >&2 || true
    exit 1
  fi
  tail -n 5 "$MIGRATE_LOG"
fi

# Carry user-managed files that live only on the server (not in Git) into
# the new release's main public root before traffic moves, so a release never
# drops them. Source: the release ~/public_html currently resolves to (the
# live site), falling back to $BASE/current. Names are configurable
# (space-separated, plain names only); missing ones are skipped and nothing
# here can fail the deploy.
CARRY_FILES="${YOLOTASK_CARRY_PUBLIC_FILES-mgm-relay.php mgm-forward.php}"
CARRY_LOG="$BASE/runtime/logs/deploy-carry.log"
carry_public_files() {
  local src_root="" dst_root="$RELEASE/apps/main/public" candidate name src dst tmp
  local -a names=()
  read -r -a names <<< "$CARRY_FILES" || true
  local public_html="${YOLOTASK_PUBLIC_HTML:-$HOME/public_html}"
  for candidate in "$public_html" "$BASE/current/apps/main/public"; do
    if [[ -n "$candidate" && -d "$candidate" ]]; then
      src_root="$(cd "$candidate" 2>/dev/null && pwd -P)" || src_root=""
      [[ -n "$src_root" ]] && break
    fi
  done
  if [[ -z "$src_root" ]]; then
    echo "[$(date -u +%FT%TZ)] $COMMIT carry: no live public root found; nothing copied" >> "$CARRY_LOG"
    return 0
  fi
  if [[ "$src_root" == "$(cd "$dst_root" && pwd -P)" ]]; then
    echo "[$(date -u +%FT%TZ)] $COMMIT carry: live root is this release; nothing to copy" >> "$CARRY_LOG"
    return 0
  fi
  for name in "${names[@]}"; do
    case "$name" in
      ""|.|..|*/*|*[!A-Za-z0-9._-]*) echo "[$(date -u +%FT%TZ)] $COMMIT carry: skipped unsafe name '$name'" >> "$CARRY_LOG"; continue;;
    esac
    src="$src_root/$name"; dst="$dst_root/$name"
    if [[ ! -e "$src" ]]; then
      echo "[$(date -u +%FT%TZ)] $COMMIT carry: $name not present in $src_root; skipped" >> "$CARRY_LOG"
      continue
    fi
    tmp="$dst_root/.carry.$name.$$"
    if rm -rf "$tmp" && cp -a "$src" "$tmp" && rm -rf "$dst" && mv "$tmp" "$dst"; then
      echo "[$(date -u +%FT%TZ)] $COMMIT carry: copied $name from $src_root" | tee -a "$CARRY_LOG"
    else
      rm -rf "$tmp" 2>/dev/null || true
      echo "[$(date -u +%FT%TZ)] $COMMIT carry: WARNING could not copy $name from $src_root" | tee -a "$CARRY_LOG" >&2
    fi
  done
  return 0
}
carry_public_files || true

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
if [[ -L "${YOLOTASK_PUBLIC_HTML:-$HOME/public_html}" ]]; then
  live_target="$(readlink "${YOLOTASK_PUBLIC_HTML:-$HOME/public_html}")"
  case "$live_target" in
    "$BASE/current/apps/main/public"|"$BASE/current/apps/main/public/") ;;
    *) echo "NOTE: public_html points at $live_target, not $BASE/current/apps/main/public; re-point it once (docs/cpanel-setup.md) so releases go live automatically.";;
  esac
fi

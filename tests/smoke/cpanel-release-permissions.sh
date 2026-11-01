#!/usr/bin/env bash
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RELEASE_SCRIPT="$REPO_ROOT/scripts/deploy/cpanel-release.sh"
TMP_ROOT="$(mktemp -d)"
trap 'rm -rf "$TMP_ROOT"' EXIT
FIXTURE_REPO="$TMP_ROOT/repository"
TEST_HOME="$TMP_ROOT/home"
DEPLOY_BASE="$TEST_HOME/yolotask-v2-live"
mkdir -p "$FIXTURE_REPO/scripts/deploy" \
  "$FIXTURE_REPO/apps/main/public" \
  "$FIXTURE_REPO/apps/earner/public" \
  "$TEST_HOME/releases"
cp "$RELEASE_SCRIPT" "$FIXTURE_REPO/scripts/deploy/cpanel-release.sh"
printf '%s\n' '<?php echo "main";' > "$FIXTURE_REPO/apps/main/public/index.php"
printf '%s\n' '<?php echo "earner";' > "$FIXTURE_REPO/apps/earner/public/index.php"
# Model the existing cPanel layout: public deployment parents, but a restrictive
# umask for the newly-created commit-specific release directory.
mkdir -p "$DEPLOY_BASE/releases"
chmod 755 "$DEPLOY_BASE" "$DEPLOY_BASE/releases"
(
  cd "$FIXTURE_REPO"
  git init -q
  git add .
  git -c user.name='YOLOTASK test' \
      -c user.email='yolotask-test@example.invalid' \
      commit -qm 'Create isolated cPanel deployment fixture'
)
run_deploy_with_restrictive_umask() {
  (
    cd "$FIXTURE_REPO"
    HOME="$TEST_HOME" YOLOTASK_DEPLOY_BASE="$DEPLOY_BASE" \
      bash -c 'umask 077; exec bash ./scripts/deploy/cpanel-release.sh' >/dev/null
  )
}
COMMIT="$(git -C "$FIXTURE_REPO" rev-parse HEAD)"
RELEASE="$DEPLOY_BASE/releases/$COMMIT"
run_deploy_with_restrictive_umask
mode="$(stat -c '%a' "$RELEASE")"
if [[ "$mode" != '755' ]]; then
  echo "FAIL: new release root mode is $mode; expected 755" >&2
  exit 1
fi
# Also repair an already-created release if it was left owner-only.
chmod 700 "$RELEASE"
run_deploy_with_restrictive_umask
mode="$(stat -c '%a' "$RELEASE")"
if [[ "$mode" != '755' ]]; then
  echo "FAIL: existing release root mode is $mode; expected 755" >&2
  exit 1
fi
for private_dir in \
  "$DEPLOY_BASE/config-private" \
  "$DEPLOY_BASE/runtime" \
  "$DEPLOY_BASE/runtime/proofs" \
  "$DEPLOY_BASE/runtime/logs" \
  "$DEPLOY_BASE/runtime/cache" \
  "$DEPLOY_BASE/runtime/exports" \
  "$DEPLOY_BASE/runtime/backups"; do
  mode="$(stat -c '%a' "$private_dir")"
  if [[ "$mode" != '700' ]]; then
    echo "FAIL: private directory $private_dir mode is $mode; expected 700" >&2
    exit 1
  fi
done
printf '%s\n' 'PASS: release root is 0755 under umask 077; private directories remain 0700.'

# Server-only files (mgm-relay.php, mgm-forward.php) are carried from the
# release ~/public_html points at into each new release before `current` moves.
new_commit() { # message
  printf '%s\n' "<?php echo '$1';" > "$FIXTURE_REPO/apps/main/public/index.php"
  git -C "$FIXTURE_REPO" add -A
  git -C "$FIXTURE_REPO" -c user.name='YOLOTASK test' -c user.email='yolotask-test@example.invalid' commit -qm "$1"
  git -C "$FIXTURE_REPO" rev-parse HEAD
}
OLD_PUBLIC="$RELEASE/apps/main/public"
printf '%s\n' '<?php // relay' > "$OLD_PUBLIC/mgm-relay.php"
printf '%s\n' '<?php // forward' > "$OLD_PUBLIC/mgm-forward.php"
chmod 644 "$OLD_PUBLIC/mgm-relay.php"; chmod 640 "$OLD_PUBLIC/mgm-forward.php"
ln -s "$OLD_PUBLIC" "$TEST_HOME/public_html"   # live site pinned to a specific release, not current
C2="$(new_commit 'second release')"
run_deploy_with_restrictive_umask
NEW_PUBLIC="$DEPLOY_BASE/releases/$C2/apps/main/public"
for f in mgm-relay.php mgm-forward.php; do
  [[ -f "$NEW_PUBLIC/$f" ]] || { echo "FAIL: $f was not carried into the new release" >&2; exit 1; }
  cmp -s "$OLD_PUBLIC/$f" "$NEW_PUBLIC/$f" || { echo "FAIL: $f content differs after carry" >&2; exit 1; }
  [[ "$(stat -c '%a' "$OLD_PUBLIC/$f")" == "$(stat -c '%a' "$NEW_PUBLIC/$f")" ]] || { echo "FAIL: $f permissions not preserved" >&2; exit 1; }
done
[[ "$(readlink "$DEPLOY_BASE/current")" == "$DEPLOY_BASE/releases/$C2" ]] || { echo "FAIL: current was not switched to the new release" >&2; exit 1; }
[[ "$(readlink "$TEST_HOME/public_html")" == "$OLD_PUBLIC" ]] || { echo "FAIL: public_html symlink was changed by the deploy" >&2; exit 1; }
grep -q 'carry: copied mgm-relay.php' "$DEPLOY_BASE/runtime/logs/deploy-carry.log" || { echo "FAIL: carry not logged" >&2; exit 1; }

# Fallback to $BASE/current when public_html is absent; a missing file is skipped, not fatal.
rm -f "$TEST_HOME/public_html" "$NEW_PUBLIC/mgm-forward.php"
C3="$(new_commit 'third release')"
run_deploy_with_restrictive_umask
P3="$DEPLOY_BASE/releases/$C3/apps/main/public"
[[ -f "$P3/mgm-relay.php" && ! -e "$P3/mgm-forward.php" ]] || { echo "FAIL: fallback via current did not carry mgm-relay.php / skip the missing file" >&2; exit 1; }
grep -q 'carry: mgm-forward.php not present' "$DEPLOY_BASE/runtime/logs/deploy-carry.log" || { echo "FAIL: missing file not logged" >&2; exit 1; }

# Nothing to carry at all (no public_html, empty list) still deploys.
C4="$(new_commit 'fourth release')"
( cd "$FIXTURE_REPO" && HOME="$TEST_HOME" YOLOTASK_DEPLOY_BASE="$DEPLOY_BASE" YOLOTASK_CARRY_PUBLIC_FILES="" bash -c 'umask 077; exec bash ./scripts/deploy/cpanel-release.sh' >/dev/null )
[[ "$(readlink "$DEPLOY_BASE/current")" == "$DEPLOY_BASE/releases/$C4" && ! -e "$DEPLOY_BASE/releases/$C4/apps/main/public/mgm-relay.php" ]] || { echo "FAIL: empty carry list should deploy and copy nothing" >&2; exit 1; }
# Custom list and an unsafe name are handled without failing.
printf 'x\n' > "$DEPLOY_BASE/releases/$C4/apps/main/public/extra.txt"
C5="$(new_commit 'fifth release')"
( cd "$FIXTURE_REPO" && HOME="$TEST_HOME" YOLOTASK_DEPLOY_BASE="$DEPLOY_BASE" YOLOTASK_CARRY_PUBLIC_FILES="extra.txt ../etc nope.php" bash -c 'umask 077; exec bash ./scripts/deploy/cpanel-release.sh' >/dev/null )
[[ -f "$DEPLOY_BASE/releases/$C5/apps/main/public/extra.txt" ]] || { echo "FAIL: custom carry list not honoured" >&2; exit 1; }
grep -q "skipped unsafe name '../etc'" "$DEPLOY_BASE/runtime/logs/deploy-carry.log" || { echo "FAIL: unsafe name not refused" >&2; exit 1; }
printf '%s\n' 'PASS: mgm-relay.php / mgm-forward.php carried from the live public_html release (perms kept, fallback to current, absent files skipped, list configurable).'


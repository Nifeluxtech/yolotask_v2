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

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
roles=(main earner advertiser reviewer admin)

# Committed portal assets must match a fresh build from shared/assets.
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
for role in "${roles[@]}"; do cp "apps/${role}/public/assets/css/app.css" "$tmp/${role}.css"; cp "apps/${role}/public/assets/js/ui.js" "$tmp/${role}.js"; done
bash scripts/build-assets.sh >/dev/null
for role in "${roles[@]}"; do
  cmp -s "$tmp/${role}.css" "apps/${role}/public/assets/css/app.css" || { echo "FAIL: $role app.css is stale; run scripts/build-assets.sh" >&2; exit 1; }
  cmp -s "$tmp/${role}.js" "apps/${role}/public/assets/js/ui.js" || { echo "FAIL: $role ui.js is stale; run scripts/build-assets.sh" >&2; exit 1; }
  grep -q "shared/views/layouts/public.php" "apps/${role}/public/index.php" || { echo "FAIL: $role portal does not use the shared public layout" >&2; exit 1; }
  test -f "apps/${role}/public/error.php" || { echo "FAIL: $role has no branded error page" >&2; exit 1; }
done

for selector in '.site-header' '.bottom-nav' '.theme-toggle' '.toast' '.stat-card' '.card-glow' '.hero' '.auth-card' '.legal-doc'; do
  grep -Fq "$selector" apps/main/public/assets/css/app.css || { echo "FAIL: missing UI selector $selector" >&2; exit 1; }
done
grep -Fq 'data-theme-toggle' shared/views/layouts/public.php shared/views/layouts/authenticated.php shared/views/layouts/auth.php || { echo "FAIL: theme toggle missing" >&2; exit 1; }

for role in earner advertiser; do
  grep -Fq "shared/routes/dashboard.php" "apps/${role}/public/dashboard.php" || { echo "FAIL: $role dashboard route missing" >&2; exit 1; }
  for page in login register; do
    grep -Fq "shared/views/layouts/auth.php" "apps/${role}/pages/${page}.php" || { echo "FAIL: $role $page does not use the shared auth layout" >&2; exit 1; }
  done
done

# User-facing views must not carry internal build language.
if grep -rIl -i -E 'staging|coming soon|lorem ipsum|design preview|in this build' apps/*/public/index.php apps/*/pages/login.php apps/*/pages/register.php apps/main/pages/legal shared/views shared/routes; then
  echo "FAIL: internal build language found in user-facing views" >&2; exit 1
fi

echo "PASS: portal assets are built from shared/assets and in sync"
echo "PASS: header, bottom nav, theme toggle, toasts, cards and page components exist"
echo "PASS: auth pages, dashboards and error pages use the shared layouts"
echo "PASS: no internal build language in user-facing views"

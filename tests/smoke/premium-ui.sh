#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
roles=(main earner advertiser reviewer admin)

hashes=()
for role in "${roles[@]}"; do
  css="apps/${role}/public/assets/css/app.css"
  test -s "$css" || { echo "FAIL: missing $css" >&2; exit 1; }
  hashes+=("$(sha256sum "$css" | awk '{print $1}')")
  test -s "apps/${role}/public/assets/js/ui.js" || { echo "FAIL: missing shared UI script for $role" >&2; exit 1; }
  grep -q "shared/views/layouts/public.php" "apps/${role}/public/index.php" || { echo "FAIL: $role portal does not use the shared public layout" >&2; exit 1; }
done

unique_hashes="$(printf '%s\n' "${hashes[@]}" | sort -u | wc -l | tr -d ' ')"
[[ "$unique_hashes" == "1" ]] || { echo "FAIL: role app.css files have diverged" >&2; exit 1; }

for selector in '.glass-card' '.glass-toast' '.glass-modal' '.glass-chip' '.glass-badge' '.mobile-nav' '.site-footer'; do
  grep -Fq "$selector" apps/main/public/assets/css/app.css || { echo "FAIL: missing UI component selector $selector" >&2; exit 1; }
done

grep -Fq 'data-modal-open="availability-dialog"' shared/views/layouts/public.php || { echo "FAIL: shared portal modal trigger missing" >&2; exit 1; }
grep -Fq 'aria-controls="availability-dialog"' shared/views/layouts/public.php || { echo "FAIL: modal trigger missing its ARIA relationship" >&2; exit 1; }
for role in earner advertiser; do
  for page in login register; do
    grep -Fq 'class="auth-body"' "apps/${role}/pages/${page}.php" || { echo "FAIL: missing upgraded ${role} ${page} page" >&2; exit 1; }
    grep -Fq 'class="site-footer auth-footer"' "apps/${role}/pages/${page}.php" || { echo "FAIL: missing auth footer on ${role} ${page}" >&2; exit 1; }
  done
done

echo "PASS: all five role portals share the responsive glass system and layout"
echo "PASS: cards, toast, modal, chip, badge, footer, and modal accessibility hooks exist"
echo "PASS: earner and advertiser auth pages use the glass layout and staging footer"

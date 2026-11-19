# YOLOTASK V2 shared UI system

## Scope

This upgrade establishes a consistent mobile-first shell across the main, earner, advertiser, reviewer, and admin subdomains. The role landing pages now render through `shared/views/layouts/public.php`; the authenticated shell contract is defined in `shared/views/layouts/authenticated.php` for later dashboard pages. Styling remains plain CSS and JavaScript, with no build-time front-end dependency.

The shared glass treatment combines translucent layers, visible cool ambient gradients, backdrop blur, inner highlights, fine borders, and restrained depth shadows. Solid fallbacks are included when blur is unavailable or users request reduced transparency. Cards, status/toast messages, native dialogs, form controls, role/status badges, chips, fixed mobile navigation, and footers use the same tokens.

## Accessibility and responsive behavior

The portal shell keeps a skip link, semantic headings, high-visibility keyboard focus, live-region feedback, touch controls at or above 44 px, and safe-area spacing for phone notches and the bottom dock. The informational modal uses native `<dialog>` behavior for Escape handling, focus management, and focus restoration; the shared script detects support before invoking it. Reduced-motion and forced-colors preferences receive dedicated styles. Registration forms retain native required/type/length validation in addition to server-side validation.

## Authentication presentation

Earner and advertiser login/registration pages share the glass card, labeled translucent inputs, password guidance, accessible success/error notices, and responsive footer. The earner and advertiser portal headers show a signed-in greeting only after the current session is revalidated against the database; sign-out is a CSRF-protected POST. Reviewer and admin pages explicitly remain staff-only previews and do not offer public registration or claim that staff authentication is ready.

## Staging boundary

The landing pages state that sign-up/sign-in are for staging validation only. Email verification is not yet implemented. Tasks, campaigns, deposits, payments, balances, withdrawals, reviewer queues, and administrative actions remain unavailable. Do not treat this release as the public production marketplace.

## Verification

Run `bash tests/smoke/premium-ui.sh`, `php tests/smoke/auth-policy.php`, `git diff --check`, and PHP syntax lint before deployment. After deploying this branch to staging, verify both role registration flows, correct-role sign-in, cross-role sign-in rejection, session revalidation, CSRF-protected sign-out, failed-login feedback, modal Escape/close/focus behavior, and mobile layouts at 320, 375, 720, and desktop widths. Confirm all linked CSS/JS return 200 and the pages have no horizontal overflow. Keep database credentials and HMAC keys in the private cPanel config directory only.

## Marketplace layer

Marketplace pages (Browse, My Products, My Sales, the product editor, the public product and order pages, and the admin review) also link `assets/css/market.css`, built by `scripts/build-assets.sh` from `shared/assets/css/market.css` into the main, earner, advertiser and admin portals. A route registers it with `yolotask_page_css('assets/css/market.css')` (done once in `shared/routes/market-helpers.php`); the authenticated and market layouts link every registered sheet after `app.css`. All marketplace classes use the `.mk-` prefix and the shared tokens: product cards with a fixed 4:3 cover frame (`.mk-media`, tinted initial placeholder when there is no cover, sale ribbon when a bonus price applies), chips derived from existing product columns, a glass search bar, a two-column product page with a sticky buy panel on desktop and a fixed buy bar on phones (`.mk-buybar`), and trust badges. Targets stay at 44 px or more; hover lift and image zoom only apply to fine pointers and are removed under reduced motion; forced-colors and reduced-transparency get solid fallbacks.

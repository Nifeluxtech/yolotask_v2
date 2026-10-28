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

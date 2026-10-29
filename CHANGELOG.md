# Changelog

## Phase 2 — Accounts and security

- Email verification after sign-up: 6-digit code or one-tap link, 30-minute expiry, five tries, 60-second resend cooldown.
- Forgot/reset password on every portal: single-use 30-minute links, all devices signed out, confirmation email.
- Account page: profile (name, phone in E.164), change password, signed-in devices with per-device and all-other sign-out, close account.
- Staff sign-in for reviewer and admin portals; staff are invite-only (48-hour set-password link). `scripts/create-staff.php` creates the first admin.
- Admin Users page: search and filter, suspend or restore (sessions revoked, last admin protected), invite reviewers and admins.
- Sign-up protection: hidden bot field, signed form timing, throwaway-domain block, 5 sign-ups per IP per hour, reset throttles.
- Security emails: new-device sign-in, password changed, account closed. Branded HTML email from no-reply@yolotask.com.ng.
- Migration runner `scripts/migrate.php` with `schema_migrations`, run automatically by the cPanel release; adopts databases where 0001 was applied by hand.
- Migration `0002_account_security.sql` (additive). Daily cleanup in `cron/daily.php`.

## Phase 1 — Shared design system and protected dashboards

- Shared Deepwater design system (`shared/assets`), built into every portal by `scripts/build-assets.sh` (output committed; no server build step).
- Shared public, auth, authenticated, document and error layouts with glass header, light/dark toggle, floating bottom nav and page transitions.
- Protected earner and advertiser dashboards: signed-out visitors are redirected to sign in, other roles receive 403.
- Production copy on all five portals; Terms, Privacy and Refunds pages on the main site.
- Portal URLs derived from config `base_urls` or the current host, so one release serves production and staging.
- No database migration.


## Unreleased

- Initial YOLOTASK V2 repository scaffold.

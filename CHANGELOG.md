# Changelog

## Phase 1 — Shared design system and protected dashboards

- Shared Deepwater design system (`shared/assets`), built into every portal by `scripts/build-assets.sh` (output committed; no server build step).
- Shared public, auth, authenticated, document and error layouts with glass header, light/dark toggle, floating bottom nav and page transitions.
- Protected earner and advertiser dashboards: signed-out visitors are redirected to sign in, other roles receive 403.
- Production copy on all five portals; Terms, Privacy and Refunds pages on the main site.
- Portal URLs derived from config `base_urls` or the current host, so one release serves production and staging.
- No database migration.


## Unreleased

- Initial YOLOTASK V2 repository scaffold.

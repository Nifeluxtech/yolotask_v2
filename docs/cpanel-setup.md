# cPanel staging setup for the starter package

This setup guide prepares a **staging shell only**. The starter does not implement the YOLOTASK marketplace, database migrations, or provider integrations.

## Before using cPanel

- Confirm Git Version Control, SSH access, PHP runtime/CLI, MariaDB/MySQL, cron, outbound HTTPS, SSL, and storage are available.
- Ask the host to confirm document roots. In particular, standard cPanel accounts may keep the primary domain on `public_html`; the host must approve the main-site layout.
- Use a read-only GitHub deploy key. Clone the private repo into a private path such as `~/repositories/yolotask-v2`.
- Do not point any live domain at the clone itself.

## Release deployment

The top-level `.cpanel.yml` invokes `scripts/deploy/cpanel-release.sh`. It checks for a clean Git tree, copies the checked-out commit into `~/yolotask-v2-live/releases/<full-commit-sha>`, creates the private runtime directories if absent, and switches `~/yolotask-v2-live/current` to the new release. It applies pending database migrations (`scripts/migrate.php`) before switching, carries the server-only main-site files described below, and does not create or alter cPanel domains or the `public_html` link.

The cPanel Git workflow is manual: update from GitHub, verify the reviewed commit SHA, then deploy. The symlink approach must be tested with the hosting provider. If symlinks are not followed by the webserver or are disallowed for document roots, stop and choose a host-approved deployment layout before proceeding.

Suggested document roots, if the host permits them:

- `earner.yolotask.com` → `~/yolotask-v2-live/current/apps/earner/public`
- `advertiser.yolotask.com` → `~/yolotask-v2-live/current/apps/advertiser/public`
- `reviewer.yolotask.com` → `~/yolotask-v2-live/current/apps/reviewer/public`
- `admin.yolotask.com` → `~/yolotask-v2-live/current/apps/admin/public`
- `yolotask.com` → use the host-approved primary-domain arrangement; do not assume it can leave `public_html`.

### Main site (`public_html`) and server-only files

On the live account `~/public_html` is a symlink to one specific release
(`~/yolotask-v2-live/releases/<sha>/apps/main/public`), not to `current`, so a
new release does not reach the main site until that link is moved by hand.
**Re-point it once** (the deploy script never changes it):

    ln -sfn ~/yolotask-v2-live/current/apps/main/public ~/public_html.new && mv -Tf ~/public_html.new ~/public_html

(`public_html` must already be a symlink; never run this over a real
directory.) After that every deploy goes live on the main site when
`current` switches. Until then the release script prints a NOTE naming the
release `public_html` points at.

That public root also holds two user-managed files that are not in Git:
`mgm-relay.php` and `mgm-forward.php` (plain files, 0644). Before switching
`current`, `scripts/deploy/cpanel-release.sh` copies each of them from the
release `~/public_html` resolves to (fallback: `~/yolotask-v2-live/current/apps/main/public`)
into the new release's `apps/main/public/`, keeping mode and timestamps
(`cp -a`), and logs each copy or skip to
`~/yolotask-v2-live/runtime/logs/deploy-carry.log`. A missing file is skipped
and never fails the deploy. The list is configurable with
`YOLOTASK_CARRY_PUBLIC_FILES` (space-separated plain file names; default
`mgm-relay.php mgm-forward.php`; empty = carry nothing). Both names are in
`.gitignore` (`/apps/main/public/…`) so a copy in the working tree never
makes it dirty and blocks a deploy. Covered by
`tests/smoke/cpanel-release-permissions.sh`.

Private runtime config belongs at `~/yolotask-v2-live/config-private/app.local.php` and `database.local.php`. Private runtime storage is under `~/yolotask-v2-live/runtime`. Neither belongs in Git or a document root. Copy and edit the examples in `config/` only into these private locations. The DB credentials must be staging-only. No local or production secrets are included in the package.

## Deployment steps

1. Clone the repository through cPanel Git Version Control with the read-only deploy key.
2. Configure SSH host-key trust and verify the remote URL.
3. In cPanel Git Version Control, pull a reviewed commit and inspect its full SHA.
4. Use the Deploy action to run the root `.cpanel.yml`. The release script must finish successfully before configuring or switching staging domains.
5. Set the staging PHP version on each host, configure private runtime paths and permissions, enable HTTPS, and point staging domains to the approved directories.
6. Load each host and verify it displays the scaffold status message. Confirm source, config, migrations, `.git`, and storage are not publicly accessible.
7. Do not apply the empty placeholder SQL migrations. Do not enable payment callbacks or production cron jobs.

## Current limitation

A deployment of this scaffold proves only that the repo can be cloned and the mobile-first shell can be served. It is not a release of YOLOTASK V2 business functionality and must not be used for live user signups, deposits, withdrawals, tasks, or financial data.

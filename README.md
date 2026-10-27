# YOLOTASK V2 — cPanel Starter Package

This archive contains the planned monorepo structure and a runnable, mobile-first PHP application shell for the new YOLOTASK repository. It is intended to be extracted into a **new private GitHub repository** and opened in GitHub Codespaces.

## Important status

This is a **starter package**, not the complete YOLOTASK marketplace and not a production payment release. The front controllers, mobile-first shell, configuration loading, basic HTTP helpers, CSRF helper, and cPanel release script are included. The feature-specific PHP pages, business services, SQL migrations, provider integrations, and automated tests are clearly marked placeholders. Placeholder SQL files are not executable migrations. Live payments and real user data must not be enabled with this package as-is.

Before a production launch, implement and test the roadmap phases, supply private environment configuration, confirm the cPanel document roots, review all unresolved product decisions, implement the Afrstar integration from its verified API contract, and run the migration/reconciliation and backup-restore drills.

## Quick start

1. Create a new **private** empty GitHub repository for YOLOTASK V2. Do not import this into the existing JavaScript/Vercel V1 repository.
2. Upload this archive and follow [`CODESPACES-IMPORT.md`](CODESPACES-IMPORT.md) to extract it on a feature branch in Codespaces.
3. Review the extracted tree and `.gitignore`, commit it, and open a pull request.
4. Follow [`docs/cpanel-setup.md`](docs/cpanel-setup.md) for staging prerequisites. Do not point production domains at this starter until the feature modules and migrations are implemented and verified.

## Stack and boundaries

- Plain PHP; no Composer runtime dependency in the starter.
- One private monorepo, separate public roots for the main site and each role app.
- Shared source, config, cron, migrations, and runtime storage remain outside public roots.
- Mobile-first responsive shell with accessible navigation and touch-friendly controls.
- cPanel deployment is manual and release-based. `.cpanel.yml` invokes a script that copies a clean Git commit into a versioned private release directory and activates a `current` symlink. Verify that the host permits symlinks and configure every virtual host before using it.

## Local smoke check

From the repository root, run a PHP development server for the main app:

```sh
php -S 127.0.0.1:8080 -t apps/main/public
```

Then open `http://127.0.0.1:8080`. The role apps can be served separately by setting their own document roots to `apps/<role>/public`. The shell runs without database credentials; database-backed behavior is not implemented yet.

## Mobile standards in the starter

The shell includes a viewport declaration, semantic landmarks, responsive layouts, large touch targets, visible keyboard focus, reduced-motion support, and a compact mobile bottom navigation. Test at narrow phone widths and with keyboard-only navigation before feature work is merged.

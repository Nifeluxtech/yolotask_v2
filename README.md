# YOLOTASK V2 — cPanel Starter Package

This repository contains the planned monorepo structure and a mobile-first PHP application shell for YOLOTASK V2. It is intended for a new private GitHub repository and development in Codespaces.

## Current status

The authentication foundation is partially implemented for earner and advertiser accounts: registration/login forms, CSRF checks, password hashing, role-bound sign-in, session rotation/revocation, HMAC-based login throttling, and the first users/security migration. The other marketplace features, later migrations, and payment integrations remain unimplemented. This is **not** the complete YOLOTASK marketplace and is not ready for production user or financial data.

Before a production launch, complete email verification and recovery, registration abuse protections, admin/reviewer provisioning, database integration tests and migration tooling, all marketplace phases, provider integrations based on verified contracts, cPanel host checks, migration reconciliation, and backup/restore drills.

## Quick start

1. Keep the V2 repository separate from the existing JavaScript/Vercel V1 repo.
2. Review `SCAFFOLD-STATUS.md` and `docs/authentication-and-authorization.md`.
3. Run `php tests/smoke/auth-policy.php` from the repository root.
4. Apply `database/migrations/0001_users_sessions_security.sql` to a fresh staging DB only, after checking engine compatibility. Later SQL files remain placeholders.
5. Follow `docs/cpanel-setup.md` for staging prerequisites. Do not point production domains at the starter.

## Stack and boundaries

- Plain PHP with PDO; no Composer runtime dependency in the starter.
- One private monorepo, separate public roots for the main site and role apps.
- Shared source, configuration, migrations, cron scripts, and runtime storage remain outside public roots.
- Mobile-first forms with viewport metadata, touch-sized controls, labels, keyboard focus, and responsive layouts.
- cPanel deployment is manual and release-based. The deployment script publishes a clean commit to a private release directory and activates a `current` symlink, subject to host support.

## Local shell check

From the repository root, run `php -S 127.0.0.1:8080 -t apps/earner/public` and open `http://127.0.0.1:8080`. Use `register.php` and `login.php` to view the forms. GET forms work without DB credentials; POST registration/login requires a staging database, migration 0001, and a private security key. No real users or payment activity should be used.

# Private configuration

Copy example files to the private runtime configuration directory described in `docs/cpanel-setup.md`. Never commit real credentials. The application can load `app.local.php` from the private runtime config directory; a local config is not included in this archive. Production startup must remain fail-closed if required configuration is missing.

`afrstar-forward.example.php` → `afrstar-forward.php` (optional): AfrStar callback hub routes for other apps on the same merchant, e.g. SPLUG. Absent = forwarding off. See docs/callbacks-and-reconciliation.md.

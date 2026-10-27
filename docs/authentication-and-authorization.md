# Authentication foundation — current scope

The earner and advertiser apps have separate public `register.php`, `login.php`, and `logout.php` endpoints. Their server-side controllers bind each endpoint to its own role. The form cannot choose `admin` or `reviewer`; staff provisioning is not implemented.

Passwords use PHP `password_hash()` and `password_verify()`. Registration requires a 12-character minimum and currently limits passwords to 72 bytes. The forms use CSRF tokens. Login errors do not reveal whether an email exists. After successful login, the PHP session ID is regenerated and the application stores only its SHA-256 hash in `user_sessions`. `Auth::currentUser()` checks the active database session and user status. Logout is POST-only, CSRF-protected, revokes the database session, and clears the cookie.

The login throttle allows at most five failures per email or source IP in a 15-minute window. Email and IP identifiers are HMACed with the private `security_key`; raw values are not stored in `login_attempts`. Configure a unique random key of at least 32 characters in the private cPanel `app.local.php` or as `YOLOTASK_SECURITY_KEY`. Never commit a real key.

## Not production complete

Migration 0001 is valid-looking MySQL/MariaDB DDL but has not yet been tested against the target hosting database. Apply it manually to staging only. Later migrations are placeholders. There is no migration runner/history table or database integration test suite.

Email verification, password-reset delivery/consumption, account recovery, registration throttling/CAPTCHA, MFA, staff provisioning, session cleanup, and independent security review are not implemented. The landing/dashboard shell is not yet an authenticated product dashboard. Do not expose open registration publicly or store real user data until those gaps are addressed.

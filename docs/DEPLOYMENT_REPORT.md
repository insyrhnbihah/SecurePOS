# SecurePOS deployment preparation report

Prepared 2026-10-02. No InfinityFree deployment, Git initialization or GitHub push
was performed. The existing database schema and application records were not changed.

## Changes

- Separate ignored local configuration and safe production configuration example.
- Portable bootstrap, canonical HTTPS production URLs, shared HTTPS detection,
  local/LAN URL detection, production error suppression and Malaysia timezone.
- Database connection uses environment-specific values and generic failure responses;
  keeps existing false/error handling consistent between PHP 7.4 and PHP 8.x.
- Secure session defaults, strict session IDs and trusted-proxy HTTPS handling.
- Private document storage resolved per environment; current external local
  attachment directory retained. Host storage stays inside protected htdocs.
- Existing face key environment variable retained, with private PHP config fallback.
- QR generation continues through SECUREPOS_BASE_URL with no fixed LAN destination.
- Local demo launcher now updates ignored local.php, not tracked app.php.
- Existing transaction-history variable typo corrected without altering the UI.
- Schema-only snapshot, repository exclusions, web access denial rules and documentation.

## Created files

- .gitignore and root .htaccess
- config/bootstrap.php
- config/local.php (machine-specific; ignored)
- config/local.example.php and config/production.example.php
- config/.htaccess
- storage/private/.htaccess and storage/private/leave_documents/.gitkeep
- database/schema.sql
- README.md
- docs/INFINITYFREE.md and this report
- tests/deployment_config_test.php, tests/check_deployment.py,
  tests/render_module.php and tests/smoke_test.py

## Modified files

- config/app.php, config/database.php, config/auth.php
- config/attendance_qr.php, config/leave.php, config/face_biometrics.php
- index.php, forgot_password.php, transaction_history.php
- Start-SecurePOS-Demo.ps1

Other business modules and historical migration scripts were not modified.

## Verification

- PHP syntax checks on the active source/configuration/test files using XAMPP PHP 7.4.33.
- 13 configuration checks: local/subfolder/LAN/HTTPS/canonical URLs, untrusted
  forwarded headers, existing tunnel detection, timezone, existing storage,
  filename validation and encryption round trips.
- Read-only rendering of 14 pages using existing eligible local users:
  login, forgot password, dashboard, POS, inventory, inventory availability,
  product expiry, attendance, users, reports, audit logs, transaction history,
  leave and change password. Rendered personal data was not printed or saved.
- Local Apache HTTP 200 for login/recovery pages; HttpOnly/SameSite session flags.
- HTTP 403 for configuration examples/local configuration, private storage,
  schema, a database backup, .gitignore and the demo launcher.
- Production canonical URL selection and rejection of HTTP production base URL
  checked without creating a populated production configuration.
- Case-sensitive literal includes/assets and face-model shard references checked.
- 15 lowercase InnoDB schema tables, no INSERT/data/privilege/drop commands.
- Application files and face model shards fall within published hosting file limits.
- Demo PowerShell launcher parsed without syntax errors; it was not launched.

No write operations were exercised for sales, inventory, users or attendance.
No real login password, encryption key or private attachment was displayed.
Production PHP 8.x, MySQL import, actual Secure cookies over TLS, successful face
login, kiosk pairing/rotation and upload/download remain live acceptance checks.
Git is unavailable on PATH and no .git directory exists, so actual Git staging
and git check-ignore were not tested. Review the staging area before any future push.

## Findings requiring manual action

- Configure host-only production.php and valid SSL. The application requires
  canonical HTTPS in production; do not disable HTTPS checks to bypass a proxy issue.
- Import approved records privately using host phpMyAdmin. Local remote MySQL
  access is not available on InfinityFree free hosting. Preserve face keys and files.
- Verify hidden .htaccess uploads and 403 responses before transferring documents.
- Verify PHP upload settings permit the existing 5 MiB document limit plus overhead.
- Verify GET_LOCK, transactions, foreign keys, session persistence and all modules
  against the host's actual PHP/MySQL runtime.
- InfinityFree browser security requires real browser testing; same-domain AJAX
  is supported, but cookie challenge expiry can affect long-running QR displays.
- Kiosk polls every five seconds; monitor hosting hits/CPU during demos.
- The existing local PHP 7.4 runtime is unsupported. No PHP installation was changed.
- No standalone Settings module exists. Deployment settings are private config files.
- Geofence constants exist, but current scanning does not enforce geolocation.
  This preparation preserves that behavior; it does not introduce a new attendance rule.

## Information needed for later deployment

Final HTTPS domain/folder; assigned MySQL hostname, full database name and username;
SSL status; PHP version/extensions and upload/session limits; chosen demo/private
data migration scope. Enter the MySQL password and existing face key privately
on the host. FTP host/username/password and domain htdocs path are needed only
when an upload is explicitly authorized; do not publish them in GitHub or reports.

## Readiness

Source is prepared for GitHub publication after staged-file/secret review. Local
configuration, backups, session files, logs, generated artifacts and private uploads
are excluded. The project is prepared for InfinityFree setup, but is not verified
for public use until credentials, import, SSL, protected storage and the live
acceptance checklist in INFINITYFREE.md are complete.

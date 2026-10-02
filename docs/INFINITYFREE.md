# InfinityFree preparation and migration

No deployment or GitHub push is authorized by this preparation task.

## Configured account and exact next steps

The non-secret template `config/production.example.php` now targets:

- Site root: `https://securepos.xo.je` (no `/SecurePOS` suffix).
- MySQL host: `sql301.infinityfree.com`, port `3306`.
- Database: `if0_43067047_securepos`; username: `if0_43067047`.

Keep `config/local.php` unchanged on XAMPP. Do not create `config/production.php`
in the local application: its presence automatically selects production unless
`SECUREPOS_ENV=local` is explicitly set. No active production file was created.

When you are ready to deploy:

1. Pause local application writes for the final migration snapshot. Back up the
   local database and private attachment directory, and preserve the existing face
   encryption key privately. Never share the database password or encryption key.
2. Export the CURRENT local `securepos` database in phpMyAdmin: Custom, SQL, all
   15 tables, structure AND data, binary columns as hexadecimal, utf8mb4 retained.
   Omit database creation/selection (`CREATE DATABASE`/`USE`), user/privilege commands
   and server-level settings. Save privately as `securepos_migration_full.sql` outside
   the repository and web root. This export has NOT been created by this task.
3. In InfinityFree phpMyAdmin select `if0_43067047_securepos` and import that fresh
   export into an empty database. Do not also import `database/schema.sql` or rerun
   migrations. Compare all 15 tables and row counts with the local source.
4. Confirm SSL is installed and valid for `securepos.xo.je`, and check PHP extensions,
   upload limits and database features listed below before enabling application use.
5. Upload the production files described below directly into the `htdocs` assigned
   to this domain, preserving case and hidden `.htaccess` files. Exclude every `.bak`
   file, `local.php`, SQL exports, secrets and development files.
6. On the host, copy the production example's contents into
   `htdocs/config/production.php`. Replace ONLY the value of `db_password`, currently
   `REPLACE_WITH_MYSQL_PASSWORD`, with your MySQL password privately. Use valid PHP
   string escaping (escape apostrophes and backslashes in a single-quoted value).
   Preserve the supplied hostname, username, database, port and base URL. Set
   `face_template_key` privately to the EXISTING encryption key if retaining enrolled
   faces. The template's key placeholder cannot decrypt existing face records.
7. Create `htdocs/storage/private/leave_documents`; verify direct browser access to
   a harmless test file returns 403 before transferring attachments. Copy existing
   documents with their original stored filenames. Confirm configuration files are
   also inaccessible through HTTP.
8. Visit `https://securepos.xo.je/login.php` and run the production acceptance
   checklist below, including login/session persistence, assets, QR scanning and
   document access. HTTP requests must redirect to HTTPS. Keep writes paused on the
   old installation once the new site becomes authoritative.

`database/schema.sql` is schema ONLY: 15 tables, no existing SecurePOS data or
accounts. Import it only for a deliberately empty installation. Existing SQL files
under `backups/` are older, incomplete snapshots (at most 14 tables); most contain
historical data, and one contains no tables or data. None is the recommended current
migration export. Use the fresh structure-and-data export in step 2 to retain records.

Source verification confirms QR URLs derive from the canonical base URL; application
redirects, assets and AJAX use relative paths on the same site (external CDN assets
remain external). Authentication and kiosk sessions use the shared HTTPS detector,
HttpOnly/SameSite cookies and strict session IDs. PHP includes and production storage
use application-relative filesystem paths, not Windows paths or URL strings.
These are local/source checks; live hosting, SSL, MySQL and session persistence still
require the post-upload checks. No schema or application behavior was changed.

## Host restrictions reviewed

### Compatibility export after error 1901

The private retry file is `backups/securepos_migration_infinityfree.sql`, derived
from `securepos_migration_full.sql`. It retains all 15 tables and 1,131 records.
Only `chk_leave_decision_metadata` and its preceding comma were removed; every
other byte is unchanged. All INSERT statements, binary hexadecimal values,
password hashes, indexes, unique keys, foreign keys, AUTO_INCREMENT values,
engines and collations remain identical. The other three leave CHECKs remain.

The reported error is consistent with MariaDB's restriction on a CHECK referencing
a foreign-key column with an `ON DELETE SET NULL` action: `reviewed_by_user_id`
is used by both the decision CHECK and `fk_leave_requests_reviewer`.
See https://jira.mariadb.org/browse/MDEV-30606. This does not establish that all
CHECKs are unsupported, and the actual hosting server version has not been verified.

PHP coverage: `leave.php` inserts Pending requests with null decision metadata
through existing defaults. `attendance.php` requires an authenticated manager and
CSRF token, validates a nonempty rejection reason, locks a Pending request, and
updates status, reviewer, timestamp and reason together in a transaction. Approval
sets the reason to null. These normal workflows retain their validation unchanged.
The removed CHECK is not fully replaced for arbitrary SQL or future write paths:
database-level enforcement of status/decision-metadata consistency is reduced.
Deleting a reviewer through SQL can also clear their ID via the retained foreign
key without resetting decision metadata. No application code or local DB changed.

Before retrying in InfinityFree phpMyAdmin:

1. Select ONLY `if0_43067047_securepos`; confirm the database name in the breadcrumb.
2. Export a backup of its current contents first if there is anything to retain.
   A failed import can leave both structures and data behind; CREATE TABLE is not
   rolled back as a complete import transaction.
3. If this database contains only the failed migration, remove all partially
   imported application tables so it is empty. In Structure, select all those
   tables, choose Drop, and confirm. If foreign keys block the operation, uncheck
   Enable foreign key checks in the confirmation screen when available. Otherwise
   use one SQL batch with `SET FOREIGN_KEY_CHECKS=0;`, explicit DROP TABLE statements
   for only those application tables, then `SET FOREIGN_KEY_CHECKS=1;`.
   Do not drop the database itself or perform any of this on local `securepos`.
   If unrelated production data exists, preserve it and resolve that separately
   before dropping anything.
4. Confirm Structure shows no tables. Import ONLY
   `securepos_migration_infinityfree.sql` with format SQL and utf8mb4 encoding.
   Do not import the original full export/schema.sql or run historical migrations.
5. Confirm no errors, all 15 tables and 1,131 records, then inspect indexes/foreign
   keys and the three retained leave CHECKs. Encrypted templates require the
   original encryption key when the application is later deployed.

The corrected export is ignored by Git and contains no connection credentials or
CREATE DATABASE/USE/user/privilege statements. Static checks compare the entire
dump and count INSERT rows; no local or remote DB was connected to during this
correction. Production parsing/import remains untested until the retry.

- InfinityFree supports PHP/MySQL and `.htaccess`:
  https://www.infinityfree.com/
- Files must be inside the domain's `htdocs`; files outside it may be deleted:
  https://forum.infinityfree.com/t/cant-access-file/82747/10
- File limits: PHP/HTML 1 MB, `.htaccess` 10 kB, other files 10 MB:
  https://forum.infinityfree.com/t/what-is-the-file-upload-size-limit/49308
- Browser security requires JavaScript and cookies. Same-domain AJAX is supported;
  external API clients and command-line checks may receive a challenge instead:
  https://forum.infinityfree.com/t/browser-security-system-features-and-limitations/49353
- Remote MySQL access from the local computer is not supported on free hosting;
  use the host's phpMyAdmin for import and administration:
  https://forum.infinityfree.com/t/can-you-use-mysql-for-seperate-apps-or-programs-other-than-the-website/9373

QR five-second polling can consume hosting hits/CPU; keep demo kiosk sessions
limited and monitor the account. Browser security cookies can expire, so exercise
long-running polling and face-model loading in a real browser after deployment.
Private medical documents are sensitive; use approved demonstration data and
confirm institutional requirements before storing actual employee data on free hosting.

## Information to obtain

- Final HTTPS domain and folder: domain root or `/SecurePOS`.
- Assigned MySQL hostname (not localhost), full database name and username.
- MySQL password: enter privately in host-only production.php, not this repository.
- PHP version and availability of mysqli/mysqlnd, mbstring, fileinfo, OpenSSL, sessions.
- Installed/valid SSL certificate and whether any reverse proxy is used.
- Actual upload_max_filesize, post_max_size, writable storage and session persistence.
- Existing face-template key, if keeping enrolled faces. Transfer it privately.
- Whether to use sanitized demo data or privately migrate the existing records.

FTP details are needed only for a separately authorized upload: server, username,
password and domain htdocs path. No InfinityFree account login is needed to prepare source.

## Database migration without schema changes

1. Keep an offline/private backup of the current database and attachment directory.
2. In local phpMyAdmin, use Custom export, select all 15 application tables, SQL,
   and include structure and data if retaining the current application records.
3. Do not include CREATE DATABASE, USE, CREATE USER, GRANT, or server-level settings.
   Export binary columns as hexadecimal (important for encrypted face templates).
   Keep utf8mb4, indexes, foreign keys, InnoDB and existing enum definitions intact.
4. Keep that data export outside Git and the public web directory. Every generic
   `.sql` file is ignored except schema.sql and historical migrations.
5. Create the assigned/prefixed database through InfinityFree's panel, select it
   in the host's phpMyAdmin, then import the private structure+data export.
   For a fresh empty installation instead, import database/schema.sql and privately
   provision approved data and a manager account. Do not import both structures.
6. Do not re-run historical migrations: their changes are already in schema.sql.
7. Compare all 15 table names, row counts, indexes, relationships and binary
   templates. Do not weaken foreign keys or change column definitions to fix an
   import error; investigate provider compatibility first.
8. Test transactions, FOR UPDATE and GET_LOCK/RELEASE_LOCK on hosting. The application
   sets connection time_zone to +08:00 without requiring named timezone tables.

The committed schema snapshot was exported read-only from MariaDB 10.4.27 on
2026-10-02. It includes no data and no database creation/privilege commands. It is
for an empty selected database and deliberately does not drop existing tables.

## Files to upload AFTER deployment approval

Upload the root application PHP files, assets/, required config/*.php helpers,
root .htaccess, config/.htaccess, and storage/private/.htaccess into the chosen
htdocs application directory. Create storage/private/leave_documents there.

Do not upload local.php, backups/, tools/, tmp/, output/, database/, migrations/,
docs/, tests/, .git/, .env files, demo launchers or development metadata.
Do not upload the example config files as active configuration; create production.php
on the host from the production example and fill in the actual values privately.

Set base_url to the final HTTPS domain, including /SecurePOS only when applicable.
Keep the same canonical domain for login, faces, kiosk and attendance. There is no
hard-coded localhost or private-network QR destination in shared configuration.

## Private document storage

Production uses `storage/private/leave_documents` inside htdocs because InfinityFree
does not retain files outside htdocs. HTTP access is denied by both the root rewrite
rule and storage/private/.htaccess; PHP's authenticated leave_document.php still
reads files directly. Preserve random stored filenames when transferring old files.
Never make this directory publicly downloadable or remove its deny rule to solve an error.

BEFORE transferring real documents, verify a harmless test file under this directory
returns 403 to a direct browser URL. Check config/production.php is also inaccessible.
Missing .htaccess files (often hidden in FTP clients) are a deployment blocker.
The existing application limit remains 5 MiB. Hosting PHP upload limits must allow
5 MiB plus form overhead; a smaller provider limit is a blocker to preserving that feature.

## Production acceptance checklist

- HTTP redirects to HTTPS; no redirect loop; production errors reveal no paths/secrets.
- config/, storage/, database/, backups/ and dotfiles cannot be downloaded or listed.
- Login/logout, account lockout, password reset/change and all role restrictions.
- Session survives requests/reload and Secure/HttpOnly/SameSite cookies are present.
- Dashboard and expiry notifications; inventory and batches; product expiry statuses.
- POS sale, totals, inventory deduction, receipt and transaction history using demo data.
- Users and face enrollment/login, including loading all local model files over HTTPS.
- Reports and audit events, date boundaries and Malaysia timestamps.
- Kiosk pairing/revocation, 60-second QR rotation, active/inactive tokens, replay rejection,
  attendance login return, check-in/check-out and operating-hour boundaries on mobile.
- QR points to the correct HTTPS production folder; no localhost/LAN destination.
- Leave application, manager approval/rejection, 5 MiB file upload, authorized download,
  unauthorized download rejection and retained filenames/metadata.
- No standalone Settings module exists. Verify production configuration separately.
- Current attendance scanning does not enforce the configured geofence; this preparation
  preserves existing behavior and does not add or silently claim location verification.

Source is prepared for hosting, but final approval depends on credentials, SQL import,
HTTPS/storage protections and the above live checks. No live deployment has been tested.

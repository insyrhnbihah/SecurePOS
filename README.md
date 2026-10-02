# SecurePOS – A Cybersecurity-Driven Retail Management System

SecurePOS is a Final Year Project retail management system built with PHP,
MySQL/MariaDB, HTML, CSS, Bootstrap and JavaScript. No framework rewrite is
required. It supports Manager, Cashier and Inventory Staff access.

## Features

- Password and face verification login, account lockout and forced password changes.
- Dashboard, POS, receipts and transaction history.
- Inventory, batch expiry monitoring and notifications.
- QR attendance, paired kiosk displays, employee records and leave requests.
- Private supporting documents, manager reviews, reports and audit logs.
- User management and encrypted face templates.

There is no standalone Settings page in this version. Deployment settings live
in configuration files. Geofence constants exist, but the current attendance
scan flow does not enforce location; do not claim otherwise in deployment testing.

## Requirements

- Apache-compatible hosting with `.htaccess` enabled and a supported PHP 8.x version.
- MySQL/MariaDB with InnoDB, transactions, foreign keys, `FOR UPDATE` and `GET_LOCK`.
- PHP mysqli/mysqlnd (`get_result`), mbstring, fileinfo, OpenSSL and sessions.
- HTTPS for production, camera access and kiosk pairing.
- JavaScript and cookies enabled. Bootstrap and fonts use external CDNs.

Local syntax and smoke tests use the existing XAMPP PHP 7.4.33 installation.
That version is end-of-life; production PHP 8.x needs the deployment checklist.

## Local setup

1. Place the project in the Apache document root and start Apache/MySQL.
2. Copy `config/local.example.php` to `config/local.php` and set your local DB values.
3. For a new EMPTY database, import `database/schema.sql` through phpMyAdmin.
   Do not reimport it into the existing working database.
4. For an existing installation, preserve the existing database and attachments.
5. Set `leave_storage` to your existing private directory, or create
   `storage/private/leave_documents`. Keep its `.htaccess` protection.
6. Keep the existing `SECUREPOS_FACE_TEMPLATE_KEY` Apache environment setting,
   or place it in ignored `local.php` as `face_template_key`.
7. Open `http://localhost/SecurePOS/` (adjust the folder name if necessary).

The current workstation's ignored `local.php` preserves its existing database and
external attachment directory. An empty `base_url` automatically resolves the
request host, protocol and installation folder locally. The optional Windows demo
launcher writes its tunnel URL into ignored `local.php`, not shared source code.
To return to automatic local URL detection after a demo, clear `base_url` there.
The Cloudflare executable is intentionally excluded from Git and must be supplied
separately if using that optional launcher.

## Configuration and secrets

`config/bootstrap.php` loads `production.php` when it exists; otherwise it loads
`local.php`. `SECUREPOS_ENV=local|production` can explicitly select the environment.
A missing local config is rejected for web requests; production never needs root
or localhost database credentials. Environment variables can override DB values,
the base URL, document storage and face key, but a `.env` file is NOT parsed.

The equivalent safe configuration examples are `config/local.example.php` and
`config/production.example.php`. Populated files are ignored by Git and blocked
from HTTP access. Create production.php only on the host and never paste secrets
into issues, reports, screenshots or public chat. PHP secrets must not be put in
JavaScript, HTML or a tracked `.htaccess` file.

The face encryption key is base64 encoding of exactly 32 random bytes. Preserve
the existing key when migrating encrypted templates; a new key cannot decrypt
them. No default key or demo account password is included in this repository.

## InfinityFree deployment

See [the deployment and migration guide](docs/INFINITYFREE.md). The repository
contains preparation files only. The production example is configured for the
created InfinityFree account at `https://securepos.xo.je`; its database password
remains a placeholder. No deployment has been performed.

PHP cookies use strict session ID handling, HttpOnly and SameSite. HTTPS detection
is shared between authentication and QR attendance. Production requires a
canonical HTTPS `base_url`, redirects HTTP to HTTPS, disables displayed errors,
and uses Malaysia time for PHP and the database connection. Forwarded HTTPS
headers are trusted only for configured proxy addresses or the existing loopback
Cloudflare demo. Use direct hosting SSL, not an unverified flexible-SSL proxy.

QR links are generated from the environment's base URL, including the installation
folder. Local relative page links, redirects and fetch calls also work when the
application is installed in a subfolder. The kiosk's existing HTTPS requirement,
60-second rotation, pairing, operating hours and attendance rules are preserved.

## Repository contents

Commit source pages, shared helpers, assets, license files, configuration examples,
schema-only SQL, migration history, documentation and tests. Do not commit backups,
private documents, session files, logs, generated reports, tunnel state or populated
configuration files. `.gitignore` is a guard, not a guarantee against force-adding
secrets; review staged files before any push. No Git repository has been initialized.

`database/schema.sql` is a schema-only snapshot of the current 15-table database.
It contains no INSERT statements or account/biometric records. Historical migration
scripts are retained for reference; do not apply them again after a full schema import.

## Validation

Run `php tests/deployment_config_test.php` for local/subfolder/HTTPS/proxy URL and
encryption/storage configuration checks. Run `python tests/check_deployment.py`
for case-sensitive source references, schema contents and hosting file sizes.
Run `python tests/smoke_test.py` while local Apache/MySQL are running for read-only
page rendering and HTTP file-protection checks. Set `SECUREPOS_TEST_PHP` and
`SECUREPOS_TEST_URL` when your executable or local URL differs from XAMPP defaults.
These checks do not replace the browser and production checks in the guide.

The project has no public first-user installer. A schema-only installation needs
approved data imported privately, or a manager account provisioned through a
trusted database administration process using a PHP-generated password hash.
Never publish a bootstrap password or turn manager creation into a public endpoint.

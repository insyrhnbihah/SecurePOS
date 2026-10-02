"""Read-only local page/config/protection checks; never prints rendered personal data."""
from pathlib import Path
import os
import subprocess
import sys
import urllib.request
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SECUREPOS_TEST_PHP', r'C:\xampp\php\php.exe')
env = os.environ.copy()
env['SECUREPOS_ENV'] = 'local'
env.pop('SECUREPOS_BASE_URL', None)
errors = []
pages = ['login.php', 'forgot_password.php', 'dashboard.php', 'pos.php', 'inventory.php',
         'inventory_availability.php', 'product_expiry.php', 'attendance.php', 'users.php',
         'reports.php', 'audit_logs.php', 'transaction_history.php', 'leave.php', 'change_password.php']
for page in pages:
    result = subprocess.run([PHP, str(ROOT / 'tests/render_module.php'), page],
                            capture_output=True, env=env, timeout=30)
    body = result.stdout.decode('utf-8', errors='replace')
    if result.returncode or result.stderr or '<html' not in body.lower() or any(
            marker in body for marker in ('Fatal error:', 'Warning:', 'Notice:', 'Parse error:', 'Deprecated:')):
        errors.append(f'{page}: rendering failed (output intentionally withheld)')
    else:
        print(f'PASS: read-only {page} render')

# Production selection/configuration checks without creating a populated production.php.
production = env.copy()
production.update(SECUREPOS_ENV='production', SECUREPOS_BASE_URL='https://shop.example.test/SecurePOS')
code = "require 'config/bootstrap.php'; echo secureposIsProduction() ? secureposBaseUrl() : 'wrong';"
result = subprocess.run([PHP, '-r', code], cwd=ROOT, capture_output=True, env=production)
if result.stdout != b'https://shop.example.test/SecurePOS' or result.stderr:
    errors.append('Production configuration selection failed')
else:
    print('PASS: production canonical QR URL without local defaults')
production['SECUREPOS_BASE_URL'] = 'http://shop.example.test'
result = subprocess.run([PHP, '-r', code], cwd=ROOT, capture_output=True, env=production)
if b'configuration is unavailable' not in result.stdout:
    errors.append('Production HTTP base URL was not rejected')
else:
    print('PASS: production HTTP base URL rejected')

base = os.environ.get('SECUREPOS_TEST_URL', 'http://localhost/SecurePOS')
checks = {'/login.php': 200, '/forgot_password.php': 200, '/config/local.php': 403,
          '/config/production.example.php': 403, '/storage/private/leave_documents/.gitkeep': 403,
          '/database/schema.sql': 403, '/backups/securepos_backup_20260828.sql': 403,
          '/.gitignore': 403, '/Start-SecurePOS-Demo.ps1': 403}
for path, expected in checks.items():
    try:
        with urllib.request.urlopen(base + path, timeout=15) as response:
            actual = response.status
            if path == '/login.php':
                cookies = response.headers.get_all('Set-Cookie', [])
                if not any('HttpOnly' in c and 'SameSite=Lax' in c for c in cookies):
                    errors.append('Local login session cookie flags missing')
    except urllib.error.HTTPError as error:
        actual = error.code
    except OSError:
        errors.append(f'{path}: local Apache unavailable')
        continue
    if actual != expected:
        errors.append(f'{path}: expected HTTP {expected}, got {actual}')
    else:
        print(f'PASS: HTTP {actual} {path}')
if errors:
    print('\n'.join(errors))
    sys.exit(1)
print('PASS: local smoke checks. No sales, attendance, user or inventory writes performed.')

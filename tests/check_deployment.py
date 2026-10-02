"""Read-only checks for deployment references, schema safety and host limits."""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
errors = []
files = list(ROOT.glob('*.php')) + list((ROOT / 'config').glob('*.php'))
files = [p for p in files if p.name not in ('local.php', 'production.php')]

def exact_reference(relative):
    cursor = ROOT
    for part in relative.split('/'):
        if part in ('', '.'):
            continue
        if not cursor.is_dir() or part not in {p.name for p in cursor.iterdir()}:
            return False
        cursor = cursor / part
    return cursor.exists()

for file in files:
    content = file.read_text(encoding='utf-8-sig')
    refs = re.findall(r"__DIR__\s*\.\s*['\"](/[^'\"]+)['\"]", content)
    for ref in refs:
        # Only literal file includes, not storage folders or runtime names.
        if ref.endswith('.php') and ref not in ('/production.php', '/local.php'):
            target = file.parent / ref.lstrip('/')
            if not exact_reference(target.relative_to(ROOT).as_posix()):
                errors.append(f'{file.name}: missing/case-mismatched include {ref}')
    for ref in re.findall(r'(?:href|src)=[\'"]([^\'"<>]+)', content):
        ref = ref.split('?')[0].split('#')[0]
        if ref and not re.search(r'[:{}$ ]', ref) and not ref.startswith('/'):
            if not exact_reference(ref):
                errors.append(f'{file.name}: missing/case-mismatched asset {ref}')
    if re.search(r"C:[/\\]|172\.20\.10\.6", content):
        errors.append(f'{file.name}: absolute workstation path/address')

schema = (ROOT / 'database/schema.sql').read_text(encoding='utf-8')
tables = re.findall(r'CREATE TABLE `([^`]+)`', schema)
if len(tables) != 15 or any(t != t.lower() for t in tables):
    errors.append('Schema must contain the 15 lowercase application tables')
if re.search(r'\b(?:INSERT\s+INTO|REPLACE\s+INTO|CREATE\s+DATABASE|CREATE\s+USER|GRANT|DEFINER|DROP\s+TABLE)\b', schema, re.I):
    errors.append('Schema contains data or server/destructive commands')

# Browser model manifests must retain exact shard filenames on Linux.
import json
models = ROOT / 'assets/vendor/face-api/models'
for manifest in models.glob('*manifest.json'):
    for group in json.loads(manifest.read_text(encoding='utf-8')):
        for shard in group['paths']:
            if shard not in {p.name for p in models.iterdir()}:
                errors.append(f'{manifest.name}: missing/case-mismatched model shard {shard}')

deploy = list(ROOT.glob('*.php')) + list((ROOT / 'config').glob('*.php')) + list((ROOT / 'assets').rglob('*'))
deploy += [ROOT / '.htaccess', ROOT / 'config/.htaccess', ROOT / 'storage/private/.htaccess']
for file in deploy:
    if not file.is_file():
        continue
    limit = 10_000 if file.name == '.htaccess' else (1_000_000 if file.suffix in ('.php', '.html') else 10_000_000)
    if file.stat().st_size > limit:
        errors.append(f'{file.relative_to(ROOT)} exceeds InfinityFree file limit')

if errors:
    print('\n'.join(errors))
    sys.exit(1)
print(f'PASS: case-sensitive includes/assets, 15-table schema safety and hosting file sizes ({len(files)} PHP files).')

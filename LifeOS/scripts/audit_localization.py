"""Read-only source-copy coverage. Never read workspace records or send text online."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('locale_catalog', ROOT / 'scripts/complete_localization.py')
catalog = importlib.util.module_from_spec(spec)
spec.loader.exec_module(catalog)
pairs = catalog.catalogs()
keys = set()
for source in (ROOT / 'app').rglob('*.php'):
    if '/locales/' in source.as_posix() or '/libraries/' in source.as_posix():
        continue
    for match in re.finditer(r'\bt\(\s*(' + catalog.Q + r')\s*\)', source.read_text(encoding='utf-8')):
        keys.add(catalog.decode(match[1]))
for source in (ROOT / 'public/assets').glob('*.js'):
    if source.name.startswith('locale-') or source.name.endswith('.min.js'):
        continue
    for match in re.finditer(r'lifeosT\(\s*(' + catalog.Q + r')\s*\)', source.read_text(encoding='utf-8')):
        keys.add(catalog.decode(match[1]))
for source in (ROOT / 'launcher').glob('*.cs'):
    for match in re.finditer(r'NativeLocale.Text\(("(?:[^"\\]|\\.)*")\)', source.read_text(encoding='utf-8')):
        keys.add(json.loads(match[1]))
static = json.loads(subprocess.check_output([sys.executable, '-X', 'utf8', str(ROOT / 'scripts/localize_views.py')], text=True, encoding='utf-8'))
# A literal technical path is deliberately displayed unchanged.
static_missing = [key for key in static['missing'] if key != 'data/backups/']
missing = sorted(keys - pairs.keys())
report = {'literal_keys_checked': len(keys), 'missing_literal_pairs': missing, 'unwrapped_static_copy': static_missing,
          'scope': 'PHP literal t() keys, browser lifeosT() keys, NativeLocale.Text() keys and literal view text. Dynamic composite sentences, runtime/external messages and fluent human review are not certified by this audit.'}
print(json.dumps(report, ensure_ascii=True, indent=2))
sys.exit(bool(missing or static_missing))

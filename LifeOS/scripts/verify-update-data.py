"""Create a SQLite update backup and verify every existing user row afterward."""
import argparse
import hashlib
import json
from pathlib import Path
import sqlite3


def signature(data):
    database = data / 'lifeos.db'
    with sqlite3.connect(database.as_uri() + '?mode=ro', uri=True) as db:
        if db.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
            raise RuntimeError('Database integrity check failed')
        result = {}
        for (table,) in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"):
            if table == 'migrations':
                continue
            quoted = '"' + table.replace('"', '""') + '"'
            rows = db.execute('SELECT * FROM ' + quoted).fetchall()
            if table == 'settings':
                rows = [row for row in rows if row[0] != 'recurrence_last_scan']
            encoded = sorted(json.dumps(row, ensure_ascii=True, default=lambda v: {'blob': v.hex()}) for row in rows)
            result[table] = {'rows': len(rows), 'sha256': hashlib.sha256('\n'.join(encoded).encode()).hexdigest()}
        uploads = data / 'uploads'
        files = {str(p.relative_to(uploads)): hashlib.sha256(p.read_bytes()).hexdigest()
                 for p in sorted(uploads.rglob('*')) if p.is_file()} if uploads.exists() else {}
        return {'tables': result, 'uploads': files}


parser = argparse.ArgumentParser()
parser.add_argument('mode', choices=['backup', 'verify'])
parser.add_argument('data', type=Path)
parser.add_argument('manifest', type=Path)
args = parser.parse_args()
data = args.data.resolve()
if args.mode == 'backup':
    target = data / 'backups' / ('lifeos-before-glass-' + __import__('datetime').datetime.now().strftime('%Y%m%d-%H%M%S') + '.db')
    target.parent.mkdir(parents=True, exist_ok=True)
    with sqlite3.connect((data / 'lifeos.db').as_uri() + '?mode=ro', uri=True) as source, sqlite3.connect(target) as dest:
        source.backup(dest)
    state = signature(data)
    state['backup'] = str(target)
    args.manifest.parent.mkdir(parents=True, exist_ok=True)
    args.manifest.write_text(json.dumps(state, indent=2), encoding='utf-8')
    print('Backup created:', target)
    print('Recorded', sum(v['rows'] for v in state['tables'].values()), 'rows across', len(state['tables']), 'tables and', len(state['uploads']), 'uploaded files.')
else:
    before = json.loads(args.manifest.read_text(encoding='utf-8'))
    after = signature(data)
    changed = [name for name, value in before['tables'].items() if after['tables'].get(name) != value]
    changed_files = [name for name, value in before['uploads'].items() if after['uploads'].get(name) != value]
    if changed or changed_files:
        raise RuntimeError('Existing data changed: tables=' + repr(changed) + ', uploads=' + repr(changed_files))
    print('Verified: every existing user row and uploaded file preserved; database integrity OK.')

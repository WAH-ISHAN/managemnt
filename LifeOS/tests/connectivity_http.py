"""Disposable HTTP acceptance for scoped API, calendar review and connection controls.
Supports Windows PHP from WSL; never opens an installed/personal data directory.
"""
import contextlib
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('LIFEOS_QA_PHP', 'php')
checks = 0
KEY = 'b' * 64


def check(value, label):
    global checks
    if not value: raise AssertionError(label)
    checks += 1


def native(path):
    if os.name != 'nt' and PHP.lower().endswith('.exe'):
        return subprocess.check_output(['wslpath', '-w', str(path)], text=True).strip()
    return str(path)


(ROOT / 'tests/.tmp').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='connectivity-http-', dir=ROOT / 'tests/.tmp') as temporary:
    sandbox = Path(temporary)
    project, data = sandbox / 'project', sandbox / 'data'
    data.mkdir()
    for folder in ('app', 'public', 'scripts'):
        shutil.copytree(ROOT / folder, project / folder)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    browser = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    api_client = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def request(path, fields=None, headers=None, client=browser, json_body=None):
        body = urllib.parse.urlencode(fields, doseq=True).encode() if fields is not None else None
        if json_body is not None: body = json.dumps(json_body).encode()
        req = urllib.request.Request(base + path, body, headers or {})
        try:
            with client.open(req, timeout=40) as result:
                return result.status, result.read().decode(), result.headers
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode(), error.headers

    def post(path, fields):
        page = request('/connectivity')[1]
        csrf = re.search(r'name="_csrf" value="([a-f0-9]+)"', page).group(1)
        return request(path, dict(fields, _csrf=csrf))

    def bearer(token, profile=None):
        headers = {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'}
        if profile: headers['X-LifeOS-Profile'] = profile
        return headers

    with (sandbox / 'server.log').open('w') as log:
        process = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=project, env=dict(os.environ, LIFEOS_DATA_DIR=native(data), LIFEOS_INSTANCE='connectivity-test', LIFEOS_AUTOMATION_KEY=KEY), stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    if request('/health')[0] == 200: break
                except OSError: pass
                time.sleep(.1)
            check(request('/health')[0] == 200, 'isolated server starts')
            code, page, _ = request('/connectivity')
            check(code == 200 and 'Encrypted external backups' in page, 'connections page renders')
            check(request('/connectivity/tokens', {'name': 'bad'})[0] == 419, 'browser token creation requires CSRF')
            code, page, _ = post('/connectivity/tokens', {'name': 'Read API', 'scopes[]': ['tasks.read']})
            read = re.search(r'readonly value="([a-f0-9]{64})"', page).group(1)
            check(code == 200, 'browser creates token once')
            code, payload, headers = request('/api/v1/tasks', client=api_client, headers=bearer(read))
            check(code == 200 and json.loads(payload)['tasks'] == [], 'cookie-less API read works')
            check('Set-Cookie' not in headers, 'API does not create browser session')
            check(request('/api/v1/tasks', client=api_client)[0] == 401, 'API rejects missing token')
            check(request('/api/v1/tasks', client=api_client, headers=dict(bearer(read), Origin=base))[0] == 401, 'API rejects browser Origin even for localhost')
            check(request('/api/v1/tasks', client=api_client, headers=bearer(read), json_body={'title': 'Denied'})[0] == 422, 'read token cannot mutate')
            code, page, _ = post('/connectivity/tokens', {'name': 'Write API', 'scopes[]': ['tasks.write', 'time.write']})
            write = re.search(r'readonly value="([a-f0-9]{64})"', page).group(1)
            code, payload, _ = request('/api/v1/tasks', client=api_client, headers=bearer(write), json_body={'title': 'CLI & task', 'due': '2026-12-05'})
            task = json.loads(payload)['id']
            check(code == 200 and task > 0, 'cookie-less API task creation works')
            check(request('/api/v1/tasks/%d/complete' % task, client=api_client, headers=bearer(write), json_body={})[0] == 200, 'API completion works')
            check(request('/api/v1/window-time', client=api_client, headers=bearer(write), json_body={'process': 'Code', 'seconds': 15, 'identity': 'one'})[0] == 422, 'tracking blocked until enabled')
            check(request('/api/v1/tasks', client=api_client, headers=bearer(read, '../default'))[0] == 403, 'profile traversal header rejected')
            # Configure through the browser and validate both presentation and data writes.
            code, page, _ = post('/connectivity/config', {'tracking_enabled': '1', 'tracking_exclusions': 'KeePass', 'tracking_retention': '7', 'backup_time': '20:00', 'calendar_tenant': 'common'})
            check(code == 200 and 'Connection preferences saved' in page, 'browser saves opt-in preferences: '+str(code)+' '+re.sub('<[^>]+>', ' ',page)[-1500:])
            code, payload, _ = request('/api/v1/window-time', client=api_client, headers=bearer(write), json_body={'process': 'Code', 'category': 'Work', 'seconds': 15, 'identity': 'one'})
            check(code == 200 and json.loads(payload)['saved'], 'tracking ingest after consent')
            request('/api/v1/window-time', client=api_client, headers=bearer(write), json_body={'process': 'Code', 'category': 'Work', 'seconds': 15, 'identity': 'one'})
            with contextlib.closing(sqlite3.connect(data / 'lifeos.db')) as db:
                check(db.execute('SELECT SUM(seconds) FROM connectivity_window_time').fetchone()[0] == 15, 'tracking retry does not double count')
                check(db.execute('SELECT status FROM tasks WHERE id=?', (task,)).fetchone()[0] == 'done', 'API uses real domain task state')
            ics = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:http-1\r\nDTSTART:20261205T030000Z\r\nDTEND:20261205T040000Z\r\nSUMMARY:HTTP <event>\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
            code, page, _ = post('/connectivity/calendar/preview', {'ics': ics})
            check(code == 200 and 'HTTP &lt;event&gt;' in page, 'ICS preview escapes source event title')
            with contextlib.closing(sqlite3.connect(data / 'lifeos.db')) as db:
                check(db.execute('SELECT COUNT(*) FROM planner_appointments').fetchone()[0] == 0, 'ICS preview has no writes')
            check(post('/connectivity/calendar/import', {})[0] == 200, 'explicit calendar import persists')
            code, body, headers = request('/connectivity/calendar/export')
            check(code == 200 and 'text/calendar' in headers['Content-Type'] and 'HTTP <event>' in body, 'calendar download exports real data')
            code, page, _ = post('/connectivity/macros', {'name': 'Daily check', 'run_at': '00:00', 'actions': '[{"type":"task","title":"Scheduled integration","due_days":0}]', 'enabled': '1'})
            check(code == 200, 'scheduled macro configured through browser')
            for _ in range(2):
                code, payload, headers = request('/internal/automation/tick', fields={}, headers={'X-LifeOS-Automation-Key': KEY}, client=api_client)
                check(code == 200 and 'ConnectivityService' in json.loads(payload), 'resident bridge invokes connections without browser cookies')
            with contextlib.closing(sqlite3.connect(data / 'lifeos.db')) as db:
                check(db.execute("SELECT COUNT(*) FROM tasks WHERE title='Scheduled integration'").fetchone()[0] == 1, 'resident repeats one daily macro once')
            check(post('/settings/pin', {'current_pin': '', 'pin': '4321', 'pin_confirm': '4321'})[0] == 200, 'set current profile PIN before creating another profile')
            code, locked_page, _ = post('/lock', {})
            check(code == 200 and 'Unlock' in locked_page, 'PIN lock hides browser connections')
            check(request('/api/v1/tasks', client=api_client, headers=bearer(read))[0] == 200, 'scoped token works independently of browser PIN lock')
            lock_token = re.search(r'name="_csrf" value="([a-f0-9]+)"', locked_page).group(1)
            code, unlocked_page, _ = request('/unlock', {'_csrf': lock_token, 'pin': '4321'})
            check(code == 200, 'browser unlock restores configuration access')
            # New profile must reject a token belonging to default even with the same server process.
            code, created_page, _ = post('/profiles/create', {'name': 'Separate', 'pin': '1234'})
            check(code == 200, 'create isolated profile: '+str(code)+' '+re.sub('<[^>]+>', ' ',created_page)[:1500])
            with contextlib.closing(sqlite3.connect(data / 'profiles.sqlite')) as registry:
                other = registry.execute("SELECT id FROM profiles WHERE id<>'default'").fetchone()[0]
            check(request('/api/v1/tasks', client=api_client, headers=bearer(read, other))[0] == 401, 'token never crosses profile databases')
            with contextlib.closing(sqlite3.connect(data / 'profiles.sqlite')) as registry:
                registry.execute('UPDATE profiles SET kid_mode=1 WHERE id=?', (other,))
                registry.commit()
            check(request('/api/v1/tasks', client=api_client, headers=bearer(read, other))[0] == 403, 'kid policy disables API')
            env = dict(os.environ, LIFEOS_API_TOKEN=read)
            cli = subprocess.run([os.sys.executable, str(ROOT / 'scripts/lifeos_cli.py'), '--url', base, 'tasks'], env=env, capture_output=True, text=True, timeout=20)
            check(cli.returncode == 0 and 'CLI & task' in cli.stdout and read not in cli.stdout, 'real CLI reads through token API')
            with contextlib.closing(sqlite3.connect(data / 'lifeos.db')) as db:
                db.execute('DELETE FROM connectivity_tokens WHERE hash=?', (hashlib.sha256(read.encode()).hexdigest(),))
                db.commit()
            check(request('/api/v1/tasks', client=api_client, headers=bearer(read))[0] == 401, 'revoked API token fails immediately')
            check('Fatal error' not in (data / 'php_error.log').read_text(errors='ignore') if (data / 'php_error.log').exists() else True, 'no PHP fatal errors')
            print(f'Connectivity HTTP: {checks} checks passed.')
        finally:
            for connection_name in ('db', 'registry'):
                connection = locals().get(connection_name)
                if connection is not None: connection.close()
            process.terminate()
            try: process.wait(5)
            except subprocess.TimeoutExpired: process.kill(); process.wait()

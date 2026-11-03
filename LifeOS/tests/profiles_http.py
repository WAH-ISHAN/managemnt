"""End-to-end profile/PIN, guardian policy and starter-pack acceptance using disposable data."""
from contextlib import closing
import http.cookiejar
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
checks = 0

def check(ok, message):
    global checks
    if not ok:
        raise AssertionError(message)
    checks += 1

def csrf(page):
    return re.search(rb'name="_csrf" value="([a-f0-9]+)"', page).group(1).decode()

with tempfile.TemporaryDirectory(prefix='lifeos-profiles-http-') as temporary:
    sandbox = Path(temporary)
    for folder in ['app', 'public']:
        shutil.copytree(ROOT / folder, sandbox / folder)
    data = sandbox / 'data'
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    browser = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path, fields=None):
        body = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            with browser.open(urllib.request.Request(base + path, body), timeout=20) as response:
                return response.status, response.read(), response.url
        except urllib.error.HTTPError as error:
            return error.code, error.read(), error.url
    def post(path, fields):
        token = csrf(request('/profiles')[1])
        return request(path, dict(fields, _csrf=token))
    with (sandbox / 'server.log').open('w') as log:
        process = subprocess.Popen([os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=sandbox, env=dict(os.environ, LIFEOS_DATA_DIR=str(data), LIFEOS_INSTANCE='profiles-http-test'), stdout=log, stderr=log, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        try:
            for attempt in range(100):
                try:
                    if request('/health')[0] == 200:
                        break
                except OSError:
                    pass
                time.sleep(.1)
            else:
                raise AssertionError('Server not ready: ' + (sandbox / 'server.log').read_text())
            code, page, _ = request('/welcome')
            check(code == 200, 'Fresh onboarding renders')
            code, _, _ = request('/welcome', {'_csrf': csrf(page), 'name': 'Adult HTTP', 'persona': 'employee', 'locale': 'en'})
            check(code == 200, 'Adult onboarding completes')
            check(post('/profiles/create', {'name': 'No parent lock', 'pin': '5678'})[0] == 422, 'Current PIN required before adding profiles')
            check(post('/settings/pin', {'pin': '1234', 'pin_confirm': '1234'})[0] == 200, 'Adult own PIN set')
            check(post('/profiles/create', {'name': 'Child HTTP', 'pin': '5678'})[0] == 200, 'Child isolated profile created')
            with closing(sqlite3.connect(data / 'profiles.sqlite')) as registry:
                child = registry.execute("SELECT id FROM profiles WHERE id<>'default'").fetchone()[0]
            check((data / 'profiles' / child / 'lifeos.db').is_file(), 'Separate database created')
            check(post('/settings/pin', {'current_pin': '1234', 'pin': '', 'pin_confirm': ''})[0] == 422, 'Every profile keeps its own PIN once family profiles exist')
            check(post('/profiles/link', {'child': child, 'guardian_pin': '1234', 'child_pin': '0000'})[0] == 422, 'Child approval proof required')
            check(post('/profiles/link', {'child': child, 'guardian_pin': '1234', 'child_pin': '5678'})[0] == 200, 'Both PINs approve guardian link')
            check(post('/profiles/policy', {'child': child, 'guardian_pin': '1234', 'kid_mode': '1'})[0] == 200, 'Guardian kid policy saved')
            check(request('/profiles/child/' + child + '/report')[0] == 200, 'Linked guardian reads constrained activity')
            check(post('/profiles/switch', {'profile': child, 'pin': '1234'})[0] == 422, 'Wrong target PIN cannot switch')
            code, page, url = post('/profiles/switch', {'profile': child, 'pin': '5678'})
            check(code == 200 and url.endswith('/kid') and b'My day' in page, 'Authenticated switch opens kid workflow')
            check(request('/planner')[2].endswith('/kid'), 'Kid planner stays in simple interface')
            check(post('/planner/tasks', {'title': 'Read chapter one', 'due': '2026-11-03'})[0] == 200, 'Child creates own task')
            with closing(sqlite3.connect(data / 'profiles' / child / 'lifeos.db')) as child_db:
                task_id = child_db.execute('SELECT id FROM tasks').fetchone()[0]
                check(child_db.execute('SELECT title FROM tasks').fetchone()[0] == 'Read chapter one', 'Child task stored in own database')
            with closing(sqlite3.connect(data / 'lifeos.db')) as adult_db:
                check(adult_db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0] == 0, 'Child writes never reach adult database')
            for path in ['/settings', '/salary', '/documents', '/automation', '/settings/export/tasks']:
                check(request(path)[0] == 403, 'Kid GET blocked: ' + path)
            for path in ['/settings/pin', '/settings/restore', '/profiles/create', '/profiles/policy', f'/planner/tasks/{task_id}/delete']:
                check(post(path, {})[0] == 403, 'Kid mutation blocked: ' + path)
            check(post('/planner/tasks/' + str(task_id) + '/status', {'status': 'done'})[0] == 200, 'Child completes task')
            check(post('/profiles/switch', {'profile': 'default', 'pin': '1234'})[0] == 200, 'Switch back authenticates adult')
            report = request('/profiles/child/' + child + '/report')[1]
            check(b'Read chapter one' in report and b'done' in report, 'Guardian report reflects actual child completion')
            check(post('/settings/workspace/packs', {'pack': 'software_engineer'})[0] == 200, 'Starter preview install succeeds')
            check(post('/settings/workspace/packs', {'pack': 'software_engineer'})[0] == 200, 'Repeated install safe')
            with closing(sqlite3.connect(data / 'lifeos.db')) as adult_db:
                check(adult_db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0] == 3, 'Repeated pack install creates only one real task set')
            check(post('/lock', {})[0] == 200, 'Adult lock succeeds')
            check(request('/profiles')[0] == 200, 'Locked profile selector accessible')
            code, _, url = post('/profiles/create', {'name': 'Bypass', 'pin': '9999'})
            check(code == 200 and url.endswith('/unlock'), 'Locked selector cannot create or administer profiles')
            check(post('/profiles/switch', {'profile': child, 'pin': '5678'})[2].endswith('/kid'), 'Can choose another profile from locked screen')
            check(post('/profiles/revoke', {'guardian': 'default'})[0] == 200, 'Child revokes guardian consent')
            post('/profiles/switch', {'profile': 'default', 'pin': '1234'})
            check(request('/profiles/child/' + child + '/report')[0] == 422, 'Revoked report unavailable to former guardian')
        finally:
            process.terminate()
            process.wait(timeout=15)
            errors = (data / 'php_error.log').read_text(errors='replace') if (data / 'php_error.log').exists() else ''
            check(not errors, 'No PHP errors: ' + errors)
print(f'{checks} profile HTTP checks passed.')

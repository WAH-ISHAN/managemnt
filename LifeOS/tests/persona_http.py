"""Disposable HTTP acceptance for persona modules and the resident automation bridge.

Run with Python's standard library and PHP; never opens an installed/personal DB.
LIFEOS_QA_PHP may select a PHP executable with the required SQLite extensions.
"""
import http.cookiejar
from html.parser import HTMLParser
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
KEY = 'a' * 64
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1


class Links(HTMLParser):
    def __init__(self, page):
        super().__init__()
        self.hrefs = []
        self.language = None
        self.feed(page.decode('utf-8'))

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == 'a':
            self.hrefs.append(attributes.get('href', ''))
        if tag == 'html':
            self.language = attributes.get('lang')


def csrf(page):
    match = re.search(rb'name="_csrf" value="([a-f0-9]+)"', page)
    if not match:
        raise AssertionError('Rendered page did not contain a CSRF field')
    return match.group(1).decode()


with tempfile.TemporaryDirectory(prefix='lifeos-persona-http-') as temporary:
    sandbox = Path(temporary)
    project = sandbox / 'project'
    data = sandbox / 'data'
    data.mkdir()
    for folder in ['app', 'public']:
        shutil.copytree(ROOT / folder, project / folder)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    php = os.environ.get('LIFEOS_QA_PHP', 'php')
    browser = urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    # The resident runner does not share an unlocked browser session or CSRF token.
    resident = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def request(path, fields=None, headers=None, method=None, client=browser):
        body = urllib.parse.urlencode(fields, doseq=True).encode() if fields is not None else None
        call = urllib.request.Request(base + path, body, headers or {}, method=method)
        try:
            with client.open(call, timeout=20) as response:
                return response.status, response.read(), response.headers, response.url
        except urllib.error.HTTPError as error:
            return error.code, error.read(), error.headers, error.url

    db = None
    with (sandbox / 'server.log').open('w', encoding='utf-8') as log:
        process = subprocess.Popen(
            [php, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
            cwd=project, env=dict(os.environ, LIFEOS_DATA_DIR=str(data),
                                 LIFEOS_INSTANCE='persona-http-qa', LIFEOS_AUTOMATION_KEY=KEY),
            stdout=log, stderr=log,
            creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        try:
            ready = False
            for _ in range(100):
                if process.poll() is not None:
                    break
                try:
                    if request('/health')[0] == 200:
                        ready = True
                        break
                except OSError:
                    pass
                time.sleep(.1)
            check(ready, 'Disposable PHP server starts')
            code, page, _, _ = request('/welcome')
            check(code == 200, 'Fresh persona onboarding renders')
            for persona in ['employee', 'uni', 'school', 'freelancer', 'business', 'job_seeker']:
                check(f'value="{persona}"'.encode() in page, f'Onboarding offers {persona}')
            token = csrf(page)
            for invalid in [{}, {'roles':'uni'}, {'roles[]':['uni','unknown']}]:
                malformed={'_csrf':token,'name':'Invalid setup','institution':'','locale':'en','template':'',**invalid}
                check(request('/welcome',malformed)[0]==422,'First-start rejects missing or invalid role selections')
            fields = {'_csrf': token, 'name': 'Persona HTTP QA', 'institution': '',
                      'persona': 'employee', 'locale': 'en', 'template': 'software_engineer'}
            code, page, _, url = request('/welcome', fields)
            check(code == 200 and not url.endswith('/welcome'), 'Employee onboarding creates a workspace')
            db = sqlite3.connect(data / 'lifeos.db')
            db.row_factory = sqlite3.Row
            profile = db.execute('SELECT * FROM local_profiles WHERE id=?', ('default',)).fetchone()
            check(profile is not None and profile['persona'] == 'employee', 'Selected persona persists in local DB')
            check(db.execute('SELECT enabled FROM modules WHERE profile_id=? AND module_id=?',
                             ('default', 'Uni')).fetchone()[0] == 0, 'Employee defaults disable University')
            check(db.execute('SELECT enabled FROM modules WHERE profile_id=? AND module_id=?',
                             ('default', 'Work')).fetchone()[0] == 1, 'Employee defaults enable Work')
            links = Links(page)
            check(not any(href == '/uni' or href.startswith('/uni/') for href in links.hrefs),
                  'Disabled university is absent from sidebar, quick actions and dashboard links')

            # A disabled module must preserve its saved records while blocking reads/writes.
            db.execute("INSERT INTO uni_modules(code,name,credits,semester) VALUES ('QA','Preserved module',3,'QA')")
            db.commit()
            count = db.execute('SELECT COUNT(*) FROM uni_modules').fetchone()[0]
            check(request('/uni')[0] == 403, 'Disabled University direct GET is blocked')
            check(request('/uni/add/assignment')[0] == 403, 'Disabled nested University GET is blocked')
            check(request('/settings/workspace/modules', {'_csrf':token,'enabled[]':['Uni']})[0]==422,
                  'Module payload cannot enable a tool without its matching role')
            check(request('/uni/module', {'_csrf': token, 'code': 'NO', 'name': 'Must not save',
                                          'credits': '3', 'semester': 'QA'})[0] == 403,
                  'Disabled University POST is blocked even with valid CSRF')
            check(db.execute('SELECT COUNT(*) FROM uni_modules').fetchone()[0] == count,
                  'Disabled POST leaves university records intact')
            check(request('/settings/workspace/modules', {'enabled[]': ['Uni']})[0] == 419,
                  'Module configuration requires CSRF')
            check(request('/settings/workspace/persona', {'_csrf': token, 'roles[]': ['employee', 'uni'], 'locale': 'en'})[0] == 200, 'Adding University alongside Employee enables the combined workspace')
            enabled = [row[0] for row in db.execute(
                'SELECT module_id FROM modules WHERE profile_id=? AND enabled=1', ('default',))]
            code, _, _, _ = request('/settings/workspace/modules',
                                    {'_csrf': token, 'enabled[]': enabled + ['Uni'], 'order[Uni]': '0'})
            check(code == 200, 'Module settings enable University')
            code, page, _, _ = request('/uni')
            check(code == 200 and '/uni' in Links(page).hrefs, 'Enabled University route and navigation return')
            check(db.execute('SELECT name FROM uni_modules').fetchone()[0] == 'Preserved module',
                  'Enabling a module preserves its existing records')
            check(db.execute('SELECT sort_order FROM modules WHERE profile_id=? AND module_id=?',
                             ('default', 'Uni')).fetchone()[0] == 0, 'Custom sidebar position persists')

            check(request('/settings/workspace/language', {'_csrf': token, 'locale': 'si'})[0] == 200,
                  'Sinhala language preference saves')
            code, page, _, _ = request('/planner')
            check(code == 200 and Links(page).language == 'si', 'A later full page uses Sinhala html lang')
            check('දින සැලසුම'.encode() in page and 'සැකසුම්'.encode() in page,
                  'Sinhala navigation labels render')
            check(db.execute("SELECT value FROM settings WHERE key='locale'").fetchone()[0] == 'si'
                  and db.execute('SELECT locale FROM local_profiles WHERE id=?', ('default',)).fetchone()[0] == 'si',
                  'Language persists consistently in settings and profile')
            check(request('/settings/workspace/language', {'_csrf': token, 'locale': 'zz'})[0] == 422,
                  'Unsupported language is rejected')
            check(request('/automation/tick', {}, {'X-LifeOS-Automation-Key': KEY})[0] == 419,
                  'Resident secret does not bypass normal automation POST CSRF')

            # Exercise the complete builder -> resident tick -> notification path,
            # using midnight/all weekdays so the test is independent of local time.
            rule = {'_csrf': token, 'name': 'HTTP resident reminder QA', 'trigger': 'daily',
                    'time': '00:00', 'weekdays[]': list(range(1, 8)), 'min_open_tasks': '0',
                    'actions[0][type]': 'notify', 'actions[0][title]': 'HTTP resident QA notification',
                    'actions[0][body]': 'One notification from an authenticated resident tick.', 'enabled': '1'}
            check(request('/automation/rules', rule)[0] == 200,
                  'HTTP builder saves an explicitly enabled custom reminder')
            saved_rule = db.execute('SELECT * FROM automation_rules WHERE name=?', (rule['name'],)).fetchone()
            check(saved_rule is not None and saved_rule['enabled'] == 1,
                  'Custom rule enablement is persisted')
            internal = '/internal/automation/tick'
            headers = {'X-LifeOS-Automation-Key': KEY}
            sessions_before = {file.name for file in (data / 'sessions').iterdir()}
            code, body, response_headers, _ = request(internal, {}, headers, client=resident)
            payload = json.loads(body)
            check(code == 200 and payload.get('ran') == 1 and payload.get('failed') == 0,
                  'Cookie-free resident tick executes the enabled due rule')
            check(response_headers.get('Set-Cookie') is None
                  and {file.name for file in (data / 'sessions').iterdir()} == sessions_before,
                  'Resident tick creates no browser cookies or session files')
            notifications = json.loads(request('/insights/alerts')[1])
            matching = [item for item in notifications['alerts']
                        if item['title'] == rule['actions[0][title]']]
            check(len(matching) == 1 and matching[0]['body'] == rule['actions[0][body]'],
                  'Resident custom reminder appears once in the notification API')
            check(db.execute('SELECT COUNT(*) FROM automation_messages WHERE title=?',
                             (rule['actions[0][title]'],)).fetchone()[0] == 1,
                  'Resident action writes one durable notification')
            payload = json.loads(request(internal, {}, headers, client=resident)[1])
            check(payload.get('ran') == 0 and db.execute('SELECT COUNT(*) FROM automation_runs WHERE rule_id=?',
                                                       (saved_rule['id'],)).fetchone()[0] == 1,
                  'A second resident tick does not repeat the same daily slot')
            notifications = json.loads(request('/insights/alerts')[1])
            check(len([item for item in notifications['alerts'] if item['title'] == rule['actions[0][title]']]) == 1,
                  'Duplicate resident tick leaves exactly one visible notification')

            # Configure a real hashed PIN directly in this disposable DB, leaving
            # the existing browser session deliberately unauthenticated.
            pin_hash = subprocess.check_output(
                [php, '-r', "echo password_hash('1234', PASSWORD_DEFAULT);"],
                creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0)).decode()
            db.execute("INSERT INTO settings(key,value) VALUES ('pin_hash',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                       (pin_hash,))
            db.commit()
            check(request('/planner')[3].endswith('/unlock'), 'PIN locks ordinary browser routes')
            code, body, response_headers, url = request(internal, {}, headers, client=resident)
            check(code == 200 and url.endswith(internal), 'Cookie-free resident POST runs while PIN locked')
            payload = json.loads(body)
            check(isinstance(payload, dict) and 'application/json' in response_headers.get('Content-Type', ''),
                  'Resident receives a bounded JSON result')
            check(b'Persona HTTP QA' not in body and pin_hash.encode() not in body and KEY.encode() not in body,
                  'Resident result does not disclose profile name, PIN hash or process secret')
            check(request(internal, {}, client=resident)[0] == 403, 'Resident POST rejects missing key')
            check(request(internal, {}, {'X-LifeOS-Automation-Key': 'b' * 64}, client=resident)[0] == 403,
                  'Resident POST rejects wrong key')
            check(request(internal, {}, {'X-LifeOS-Automation-Key': KEY[:-1]}, client=resident)[0] == 403,
                  'Resident POST rejects truncated key')
            check(request(internal + '?key=' + KEY, {}, client=resident)[0] == 403,
                  'Query string cannot replace resident authorization header')
            for origin in ['https://evil.example', base, 'null', '']:
                check(request(internal, {}, dict(headers, Origin=origin), client=resident)[0] == 403,
                      f'Resident rejects browser Origin header {origin!r}')
            check(request(internal, headers=headers, client=resident)[0] == 405,
                  'Resident endpoint rejects GET even with correct key')
            check(request('/automation/tick', {}, headers, client=resident)[0] == 419,
                  'PIN-locked ordinary automation route still requires CSRF')
            check(db.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
                  and db.execute('PRAGMA foreign_key_check').fetchone() is None,
                  'Persona and resident HTTP flows preserve database integrity')
            errors = data / 'php_error.log'
            check(not errors.exists() or not errors.read_text(encoding='utf-8').strip(),
                  'Persona and resident routes produce no PHP application errors')
            print(f'{checks} persona and resident HTTP checks passed.')
        finally:
            if db is not None:
                db.close()
            process.terminate()
            try:
                process.wait(timeout=15)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)

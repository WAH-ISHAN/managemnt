"""Isolated university calendar HTTP acceptance; no real university requests."""
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

root = Path(__file__).resolve().parents[1]
checks = 0


def check(condition, label):
    global checks
    assert condition, label
    checks += 1


def event(remote_id, **changes):
    value = dict(lectureId=remote_id, moduleName='Database <script>alert(731)</script>',
                 lectureType='LECTURE_PHYSICAL', startAt='2026-10-30T03:30:00Z',
                 endAt='2026-10-30T05:30:00Z', status='APPROVED',
                 facilityName='Hall <img src=x onerror=alert(732)>')
    value.update(changes)
    return value


with tempfile.TemporaryDirectory(prefix='lifeos-uni-calendar-http-') as temporary:
    data = Path(temporary)
    project = data / 'project'
    for folder in ['app', 'public']:
        shutil.copytree(root / folder, project / folder)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
        urllib.request.ProxyHandler({}))

    def request(path, fields=None, headers=None):
        body = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            with opener.open(urllib.request.Request(base + path, body, headers or {}), timeout=15) as response:
                return response.status, response.read(), response.headers, response.url
        except urllib.error.HTTPError as error:
            return error.code, error.read(), error.headers, error.url

    def token(body):
        return re.search(rb'name="_csrf" value="([a-f0-9]+)"', body).group(1).decode()

    db = None
    with (data / 'server.log').open('w') as log:
        server = subprocess.Popen(
            [os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
            cwd=project, env=dict(os.environ, LIFEOS_DATA_DIR=temporary), stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    if request('/health')[0] == 200:
                        break
                except OSError:
                    pass
                time.sleep(.1)
            csrf = token(request('/')[1])
            check(request('/welcome', {'_csrf': csrf, 'name': 'Calendar QA owner', 'institution': 'QA', 'areas[]': 'study'})[0] == 200,
                  'Disposable workspace onboarding succeeds')
            code, page, _, _ = request('/uni/calendar')
            check(code == 200 and b'calendar' in page.lower(), 'University calendar setup page renders')
            code, body, headers, _ = request('/uni/calendar/config')
            payload = json.loads(body)
            config = payload.get('config', payload)
            check(code == 200 and 'application/json' in headers.get('Content-Type', ''), 'Native connector configuration is JSON')
            check(config['enabled'] is False and bool(payload['csrf']), 'Fresh config is disabled and exposes current CSRF to local connector')
            db = sqlite3.connect(data / 'lifeos.db')
            db.row_factory = sqlite3.Row
            check(db.execute('SELECT COUNT(*) FROM uni_calendar_events').fetchone()[0] == 0, 'Fresh database contains no imported lectures')
            batch = '1f8d4470-5a5a-4f90-98df-af06b2a73422'
            first = 'cc7f12ba-6eef-4bc0-9432-99f9875eca01'
            second = 'cc7f12ba-6eef-4bc0-9432-99f9875eca02'
            url = 'https://aca.mynibm.com/dashboard?view=month&year=2026&month=9&batch=' + batch
            check(request('/uni/calendar/connect', {'portal_url': url})[0] == 419, 'Connect requires CSRF')
            check(request('/uni/calendar/connect', {'_csrf': csrf, 'portal_url': 'https://evil.example/dashboard?batch=' + batch})[0] == 422,
                  'Untrusted provider host refused through HTTP')
            check(request('/uni/calendar/connect', {'_csrf': csrf, 'portal_url': url})[0] == 200, 'Selected NIBM batch connection saves')
            config = json.loads(request('/uni/calendar/config')[1])
            config = config.get('config', config)
            check(config['enabled'] and config['batchId'] == batch, 'Connector receives saved authoritative batch')
            fields = dict(_csrf=csrf, batch_id=batch, window_start='2026-10-01', window_end='2026-10-31', lectures=json.dumps([event(first)]))
            no_csrf = dict(fields)
            del no_csrf['_csrf']
            check(request('/uni/calendar/import', no_csrf)[0] == 419, 'Import refuses missing CSRF')
            check(request('/uni/calendar/import', fields, {'Origin': 'https://evil.example'})[0] in (403, 419),
                  'Cross-origin import refused even with a valid token')
            check(request('/uni/calendar/import', fields)[0] == 200, 'Valid local snapshot imports through HTTP')
            saved = db.execute('SELECT p.* FROM planner_appointments p JOIN uni_calendar_events e ON e.appointment_id=p.id WHERE e.remote_id=?', (first,)).fetchone()
            appointment = saved['id']
            check(saved['starts_at'] == '2026-10-30 09:00' and saved['ends_at'] == '2026-10-30 11:00', 'HTTP import stores exact Colombo times')
            check(request('/uni/calendar/import', fields)[0] == 200, 'Repeated HTTP snapshot accepted')
            check(db.execute('SELECT COUNT(*) FROM uni_calendar_events').fetchone()[0] == 1 and
                  db.execute('SELECT appointment_id FROM uni_calendar_events WHERE remote_id=?', (first,)).fetchone()[0] == appointment,
                  'Repeated HTTP sync does not duplicate appointments')
            alerts = json.loads(request('/insights/alerts')[1])
            check(alerts['unreadCount'] == 1 and len([item for item in alerts['alerts'] if item['kind'] == 'calendar']) == 1,
                  'A new lecture creates one unread calendar notification')
            check(db.execute('SELECT COUNT(*) FROM uni_calendar_notifications').fetchone()[0] == 1,
                  'An unchanged repeat sync does not create a duplicate notification')
            notification_id = alerts['alerts'][0]['id']
            check(request('/insights/alerts/' + notification_id + '/read', {'_csrf': csrf})[0] == 200,
                  'Read action requires and accepts local CSRF')
            check(json.loads(request('/insights/alerts')[1])['unreadCount'] == 0,
                  'Read notification leaves unread count')
            changed_event = event(first, startAt='2026-10-30T04:00:00Z',
                                  endAt='2026-10-30T05:30:00Z', facilityName='Hall B')
            changed_fields = dict(fields, lectures=json.dumps([changed_event]))
            check(request('/uni/calendar/import', changed_fields)[0] == 200,
                  'Changed lecture refreshes its linked appointment')
            alerts = json.loads(request('/insights/alerts')[1])
            check(alerts['unreadCount'] == 1 and len([item for item in alerts['alerts'] if item['kind'] == 'calendar']) == 2,
                  'A changed date, title or room creates one new notification')
            cancelled = dict(fields, lectures=json.dumps([event(first, status='CANCELLED')]))
            check(request('/uni/calendar/import', cancelled)[0] == 200,
                  'Confirmed cancellation refreshes the linked appointment')
            check(json.loads(request('/insights/alerts')[1])['unreadCount'] == 2 and
                  db.execute('SELECT COUNT(*) FROM uni_calendar_notifications').fetchone()[0] == 3,
                  'Cancellation creates one new unread calendar notification')
            check(request('/uni/calendar/import', cancelled)[0] == 200 and
                  db.execute('SELECT COUNT(*) FROM uni_calendar_notifications').fetchone()[0] == 3,
                  'Repeated cancellation does not create another notification')
            check(request('/insights/alerts/read-all', {'_csrf': csrf})[0] == 200 and
                  json.loads(request('/insights/alerts')[1])['unreadCount'] == 0,
                  'Read-all clears the notification unread count')
            code, page, _, _ = request('/uni/calendar')
            check(code == 200 and b'<script>alert(731)</script>' not in page and b'<img src=x onerror=alert(732)>' not in page,
                  'Imported title and facility cannot inject HTML in calendar page')
            code, page, _, _ = request('/planner?view=appointments&date=2026-10-30')
            check(code == 200 and b'alert(731)' in page and b'<script>alert(731)</script>' not in page and
                  b'<img src=x onerror=alert(732)>' not in page,
                  'Imported lecture appears as escaped planner content')

            def state():
                return [tuple(row) for row in db.execute('SELECT * FROM uni_calendar_events ORDER BY id')], [tuple(row) for row in db.execute('SELECT * FROM planner_appointments ORDER BY id')], [tuple(row) for row in db.execute('SELECT * FROM uni_calendar_notifications ORDER BY id')]

            before = state()
            malformed = [('broken JSON', dict(fields, lectures='{broken')),
                         ('JSON object instead of a lecture list', dict(fields, lectures='{}')),
                         ('a different batch', dict(fields, batch_id='325cbb66-7bee-45b9-bd94-f21d1defc01d')),
                         ('invalid time after an otherwise valid new lecture', dict(fields, lectures=json.dumps([event(second), event(first, endAt='invalid')]))),
                         ('unknown lecture status', dict(fields, lectures=json.dumps([event(first, status='UNKNOWN_STATUS')])))]
            for label, invalid in malformed:
                actual_code = request('/uni/calendar/import', invalid)[0]
                check(actual_code == 422, f'Reject {label} through HTTP (got {actual_code})')
                check(state() == before, f'Rejected {label} leaves imported and planner records intact')
            check(request('/uni/calendar/error', {'_csrf': csrf, 'message': 'NIBM could not be reached. Your saved lectures are unchanged.'})[0] == 200,
                  'Connector can record a bounded connection error')
            check(state() == before, 'Connection failure does not cancel existing lectures')
            check(request('/uni/calendar/import', dict(fields, lectures='[]'))[0] == 200, 'Successful complete empty snapshot accepted')
            check(db.execute('SELECT status FROM planner_appointments WHERE id=?', (appointment,)).fetchone()[0] == 'cancelled',
                  'Confirmed absence cancels the previously imported lecture')
            check(request('/uni/calendar/pause', {'_csrf': csrf})[0] == 200, 'Pause saves without deleting records')
            before = state()
            check(request('/uni/calendar/import', fields)[0] == 422, 'Paused connection blocks background imports')
            check(state() == before, 'Paused import preserves existing event history')

            check(request('/settings/pin', {'_csrf': csrf, 'pin': '1234', 'pin_confirm': '1234'})[0] == 200, 'Disposable app PIN enabled')
            request('/lock', {'_csrf': csrf})
            check(request('/uni/calendar')[3].endswith('/unlock'), 'PIN guards university calendar page')
            code, body, _, final_url = request('/uni/calendar/config')
            check(final_url.endswith('/unlock') and b'Unlock LifeOS' in body, 'Locked app does not reveal connector config')
            before = state()
            code, body, _, final_url = request('/uni/calendar/import', fields)
            check(final_url.endswith('/unlock') and state() == before, 'Locked import cannot modify saved appointments')
            check(db.execute('PRAGMA integrity_check').fetchone()[0] == 'ok' and db.execute('PRAGMA foreign_key_check').fetchone() is None,
                  'HTTP calendar flows preserve database integrity and references')
            errors = data / 'php_error.log'
            check(not errors.exists() or not errors.read_text(encoding='utf-8').strip(),
                  'Calendar routes produce no PHP application errors')
            print(f'{checks} University calendar HTTP checks passed.')
        finally:
            if db is not None:
                db.close()
            server.terminate()
            server.wait(timeout=15)

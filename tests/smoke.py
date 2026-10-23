"""End-to-end HTTP smoke test using an isolated temporary Crab database."""
import http.cookiejar
import os
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FUTURE = (date.today() + timedelta(days=2)).isoformat()
LATER = (date.today() + timedelta(days=7)).isoformat()
PAST = (date.today() - timedelta(days=1)).isoformat()
TODAY = date.today().isoformat()


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def get(self, path='/'):
        try:
            with self.opener.open(self.base + path) as response:
                return response.status, response.read().decode('utf-8')
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode('utf-8')

    def post(self, action, page, **values):
        _, html = self.get('/?page=' + page)
        token = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
        assert token, 'CSRF token missing'
        data = urllib.parse.urlencode(dict(action=action, return_page=page, csrf=token.group(1), **values)).encode()
        request = urllib.request.Request(self.base + '/', data=data)
        try:
            with self.opener.open(request) as response:
                return response.status, response.read().decode('utf-8')
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode('utf-8')


with tempfile.TemporaryDirectory(prefix='crab-test-') as directory:
    probe = socket.socket()
    probe.bind(('127.0.0.1', 0))
    port = probe.getsockname()[1]
    probe.close()
    env = dict(os.environ, CRAB_DATA_DIR=directory)
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'app/public'], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        admin = Client(f'http://127.0.0.1:{port}')
        for _ in range(50):
            try:
                admin.get()
                break
            except urllib.error.URLError:
                time.sleep(.1)
        assert 'Welcome back' in admin.get()[1]
        status, html = admin.post('register', 'register', name='Owner', email='owner@example.test', password='correct horse')
        assert status == 200 and 'Admin center' in html
        assert 'Good ' in admin.get()[1]
        assert admin.get('/?page=admin')[0] == 200
        forged = urllib.request.Request(admin.base + '/', data=b'action=add_item&kind=task', method='POST')
        try:
            admin.opener.open(forged)
            raise AssertionError('CSRF request was accepted')
        except urllib.error.HTTPError as error:
            assert error.code == 419
        hostile_host = urllib.request.Request(admin.base + '/', headers={'Host': 'evil.test'})
        try:
            admin.opener.open(hostile_host)
            raise AssertionError('DNS rebinding host was accepted')
        except urllib.error.HTTPError as error:
            assert error.code == 400
        assert admin.get('/?page=calendar')[0] == 200
        assert admin.post('add_item', 'calendar', kind='event', title='Planning', details='At home', due_at=FUTURE, points='5')[0] == 200
        assert 'Planning' in admin.get('/?page=calendar')[1]
        assert admin.post('update_item', 'calendar', id='1', operation='edit', title='Weekly planning', details='At home', due_at=LATER, points='5')[0] == 200
        assert 'Weekly planning' in admin.get('/?page=calendar')[1]
        assert admin.post('add_item', 'exams', kind='exam', title='Math exam', details='', due_at=FUTURE, points='40')[0] == 200
        assert admin.post('add_item', 'targets', kind='target', title='Read five books', details='', due_at=LATER, points='50')[0] == 200
        assert admin.post('update_item', 'targets', id='3', operation='progress', progress='60')[0] == 200
        assert '60% complete' in admin.get('/?page=targets')[1]
        user = Client(f'http://127.0.0.1:{port}')
        status, html = user.post('register', 'register', name='Member', email='member@example.test', password='long password')
        assert status == 200 and 'Admin center' not in html
        assert user.get('/?page=tasks')[0] == 403
        assert user.get('/?page=admin')[0] == 403
        assert user.post('grant', 'admin', user_id='2', module='salary', enabled='1')[0] == 403
        assert user.post('add_item', 'tasks', kind='task', title='Blocked', details='', due_at=FUTURE, points='10')[0] == 403
        assert admin.post('grant', 'admin', user_id='2', module='tasks', enabled='1')[0] == 200
        assert user.get('/?page=tasks')[0] == 200
        status, html = user.post('add_item', 'tasks', kind='task', title='<script>alert(1)</script>', details='Plan', due_at=FUTURE, points='20')
        assert status == 200 and '&lt;script&gt;' in html and '<script>alert(1)</script>' not in html
        assert user.post('update_item', 'tasks', id='1', operation='delete')[0] == 200
        assert 'Weekly planning' in admin.get('/?page=calendar')[1], 'Cross-user deletion must fail'
        assert user.post('update_item', 'tasks', id='4', operation='edit', title='Edited task', details='Changed', due_at=PAST, points='25')[0] == 200
        assert 'Edited task' in user.get('/?page=tasks')[1]
        assert user.post('update_item', 'tasks', id='4', operation='failed')[0] == 200
        assert 'Edited task' in user.get('/?page=recovery')[1]
        assert user.post('update_item', 'recovery', id='4', operation='reschedule', due_at=LATER)[0] == 200
        assert 'Edited task' not in user.get('/?page=recovery')[1]
        assert user.post('update_item', 'tasks', id='4', operation='done')[0] == 200
        assert '25' in user.get('/?page=overview')[1]
        assert user.post('update_item', 'tasks', id='4', operation='delete')[0] == 200
        assert 'Edited task' not in user.get('/?page=tasks')[1]
        assert admin.post('add_money', 'salary', kind='salary', label='Monthly pay', amount='1000', entry_date=TODAY)[0] == 200
        assert admin.post('grant', 'admin', user_id='2', module='salary', enabled='1')[0] == 200
        assert user.post('edit_money', 'salary', id='1', kind='salary', label='Stolen', amount='1', entry_date=TODAY)[0] == 200
        assert 'Monthly pay' in admin.get('/?page=salary')[1], 'Cross-user money edit must fail'
        assert admin.post('edit_money', 'salary', id='1', kind='expense', label='Rent', amount='250', entry_date=PAST)[0] == 200
        assert 'Rent' in admin.get('/?page=salary')[1]
        assert admin.post('delete_money', 'salary', id='1')[0] == 200
        assert 'Rent' not in admin.get('/?page=salary')[1]
        assert admin.post('grant', 'admin', user_id='2', module='salary', enabled='0')[0] == 200
        assert user.post('add_money', 'salary', kind='salary', label='Illegal', amount='999', entry_date=TODAY)[0] == 403
        assert admin.post('grant', 'admin', user_id='2', module='tasks', enabled='0')[0] == 200
        assert user.get('/?page=tasks')[0] == 403
        assert admin.post('update_item', 'calendar', id='1', operation='delete')[0] == 200
        assert admin.post('update_item', 'exams', id='2', operation='delete')[0] == 200
        assert admin.post('update_item', 'targets', id='3', operation='delete')[0] == 200
        print('PASS: auth, admin grants, module locks, calendar/exams/targets/tasks CRUD, recovery, salary CRUD, cross-user isolation, escaping')
    finally:
        server.terminate()
        server.wait(timeout=5)

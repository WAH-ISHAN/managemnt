"""Disposable real HTTP acceptance for review, debit and focus event flows.

Set LIFEOS_QA_PHP if PHP is not on PATH. Supports Windows PHP launched from WSL.
"""
import http.cookiejar
import io
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
import wave

ROOT = Path(__file__).resolve().parents[1]
checks = 0

def check(ok, label):
    global checks
    if not ok:
        raise AssertionError(label)
    checks += 1

php = os.environ.get('LIFEOS_QA_PHP', 'php')

def native(path):
    if os.name != 'nt' and php.lower().endswith('.exe'):
        return subprocess.check_output(['wslpath', '-w', str(path)], text=True).strip()
    return str(path)

(ROOT / 'tests/.tmp').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='intelligence-http-', dir=ROOT / 'tests/.tmp') as temporary:
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
    client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path, fields=None, raw=None, headers=None):
        req = urllib.request.Request(base + path, raw if raw is not None else urllib.parse.urlencode(fields, doseq=True).encode() if fields is not None else None, headers or {})
        try:
            with client.open(req, timeout=20) as res:
                return res.status, res.read().decode('utf-8'), res.url
        except urllib.error.HTTPError as err:
            return err.code, err.read().decode('utf-8'), err.url
    def post(path, fields):
        page = request('/intelligence')[1]
        token = re.search(r'name="_csrf" value="([a-f0-9]+)"', page).group(1)
        return request(path, dict(fields, _csrf=token))
    with (sandbox / 'server.log').open('w') as log:
        proc = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=project, env=dict(os.environ, LIFEOS_DATA_DIR=native(data), LIFEOS_INSTANCE='intelligence-http-test'), stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    if request('/health')[0] == 200:
                        break
                except OSError:
                    pass
                time.sleep(.1)
            check(request('/health')[0] == 200, 'isolated HTTP server starts')
            code, page, _ = request('/intelligence')
            check(code == 200 and 'Notes → action items' in page, 'local assistant page renders')
            check(request('/intelligence/note', {'body': 'Action: malicious'})[0] == 419, 'CSRF required before note writes')
            code, page, url = post('/intelligence/note', {'body': 'Action: Send & review <report>\n- Call team'})
            check(code == 200 and '?source=' in url and '&lt;report&gt;' in page, 'source saved and escaped in review')
            source_id = int(url.split('source=')[1])
            code, page, url = post(f'/intelligence/source/{source_id}/preview', {'body': 'Action: Send & review <report>\n- Call team'})
            check(code == 200 and 'Confirm and apply' in page, 'editable task preview renders')
            draft_id = int(url.split('draft=')[1])
            db = sqlite3.connect(data / 'lifeos.db')
            check(db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0] == 0, 'preview creates no tasks')
            code, page, _ = post(f'/intelligence/draft/{draft_id}/apply', {'items[0][title]': 'Send reviewed report', 'items[0][due]': '2026-11-04', 'items[1][title]': 'Call team', 'items[1][due]': ''})
            check(code == 200 and db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0] == 2, 'HTTP apply creates reviewed tasks')
            check(post(f'/intelligence/draft/{draft_id}/apply', {})[0] == 422, 'HTTP repeated apply rejected')
            task_id = db.execute('SELECT id FROM tasks ORDER BY id LIMIT 1').fetchone()[0]
            code, page, url = post('/intelligence/move', {'command': f'move task #{task_id} to 2026-12-01'})
            move_id = int(url.split('draft=')[1])
            check(code == 200 and '2026-12-01' in page, 'natural-language move preview renders affected task')
            check(post(f'/intelligence/draft/{move_id}/apply', {})[0] == 200 and db.execute('SELECT due FROM tasks WHERE id=?', (task_id,)).fetchone()[0] == '2026-12-01', 'move applies via HTTP')
            check(post(f'/intelligence/draft/{move_id}/undo', {})[0] == 200 and db.execute('SELECT due FROM tasks WHERE id=?', (task_id,)).fetchone()[0] == '2026-11-04', 'undo restores due date via HTTP')
            check(post('/intelligence/ask', {'question': 'Monthly spending?'})[0] == 422, 'model opt-in enforced over HTTP')
            check(request('/intelligence/voice', {})[0] == 419, 'microphone transcription requires CSRF')
            check(post('/intelligence/voice', {})[0] == 422, 'microphone endpoint rejects missing audio')
            audio = io.BytesIO()
            with wave.open(audio, 'wb') as wav:
                wav.setnchannels(1); wav.setsampwidth(2); wav.setframerate(16000); wav.writeframes(b'\0\0' * 16000)
            csrf = re.search(r'name="_csrf" value="([a-f0-9]+)"', request('/intelligence')[1]).group(1)
            boundary = 'lifeos-voice-http-fixture'
            body = (f'--{boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n{csrf}\r\n'
                    f'--{boundary}\r\nContent-Disposition: form-data; name="media"; filename="synthetic.wav"\r\nContent-Type: audio/wav\r\n\r\n').encode() + audio.getvalue() + f'\r\n--{boundary}--\r\n'.encode()
            source_count = db.execute('SELECT COUNT(*) FROM intelligence_sources').fetchone()[0]
            code, page, _ = request('/intelligence/voice', raw=body, headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})
            check(code == 422 and 'executable' in json.loads(page)['error'], 'real WAV upload reports unconfigured local recognizer as JSON')
            check(db.execute('SELECT COUNT(*) FROM intelligence_sources').fetchone()[0] == source_count, 'failed conversion creates no transcript source')
            check(not list((data / 'intelligence-tmp').glob('*')), 'failed microphone conversion deletes temporary audio')
            code, page, _ = request('/focus')
            check(code == 200 and 'Send reviewed report' in page, 'focus offers saved task links')
            code, _, _ = post('/focus/start', {'subject': 'HTTP focus', 'minutes': '25', 'kind': 'study', 'task_id': str(task_id), 'minimum_minutes': '25'})
            check(code == 200, 'focus linked session starts via HTTP')
            session_id = db.execute('SELECT id FROM focus_sessions ORDER BY id DESC LIMIT 1').fetchone()[0]
            db.execute('UPDATE focus_sessions SET segment_started=? WHERE id=?', (int(time.time()) - 60, session_id))
            db.commit()
            check(post(f'/focus/{session_id}/action', {'action': 'finish'})[0] == 200 and db.execute('SELECT COUNT(*) FROM focus_task_logs').fetchone()[0] == 1, 'completion creates exactly one task time log via HTTP')
            check(post(f'/focus/{session_id}/action', {'action': 'finish'})[0] == 200 and db.execute('SELECT COUNT(*) FROM focus_task_logs').fetchone()[0] == 1, 'repeated finish remains idempotent')
            check(request('/automation')[0] == 200, 'automation weather form renders')
            check(post('/automation/weather', {'enabled': 'on', 'latitude': '91', 'longitude': '80'})[0] == 422, 'weather coordinates validated via HTTP')
            check(db.execute('PRAGMA foreign_key_check').fetchall() == [], 'HTTP workflows preserve relational integrity')
            db.close()
        finally:
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait()
        log.flush()
    errors = (sandbox / 'server.log').read_text()
    check('PHP Fatal' not in errors and 'PHP Warning' not in errors, 'no PHP warnings or fatals in real HTTP flows')
print(f'{checks} intelligence HTTP checks passed.')

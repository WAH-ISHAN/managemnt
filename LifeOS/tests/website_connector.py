"""Exercise the reference website connector against a disposable PHP server/store."""
from contextlib import contextmanager, closing
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PROTOCOL = 'lifeos-admin-v1'
CHECKS = 0


def check(condition, label):
    global CHECKS
    if not condition:
        raise AssertionError(label)
    CHECKS += 1


@contextmanager
def server(project, database, token, allow_http=True):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, LIFEOS_CONNECTOR_DB=str(database), LIFEOS_CONNECTOR_TOKEN=token,
               LIFEOS_CONNECTOR_SITE_NAME='Connector test site', LIFEOS_CONNECTOR_ALLOW_LOCAL_HTTP='1' if allow_http else '0')
    log_path = project.parent / ('php-' + secrets.token_hex(4) + '.log')
    with log_path.open('w', encoding='utf-8') as log:
        process = subprocess.Popen([os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{port}', '-t', str(project / 'public')],
                                   cwd=project, env=env, stdout=log, stderr=log,
                                   creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
        base = f'http://127.0.0.1:{port}'
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

        def request(payload=None, *, auth=True, raw=None, content_type='application/json', method='POST', path='/api/lifeos-admin.php', headers=None):
            body = raw if raw is not None else (json.dumps(payload or {}).encode('utf-8') if method == 'POST' else None)
            request_headers = {'Content-Type': content_type, **(headers or {})}
            if auth:
                request_headers['Authorization'] = 'Bearer ' + (token if auth is True else auth)
            req = urllib.request.Request(base + path, data=body, headers=request_headers, method=method)
            try:
                with opener.open(req, timeout=10) as response:
                    return response.status, response.read(), response.headers
            except urllib.error.HTTPError as error:
                return error.code, error.read(), error.headers

        try:
            for _ in range(100):
                if process.poll() is not None:
                    raise RuntimeError('Connector PHP test server stopped: ' + log_path.read_text(encoding='utf-8'))
                try:
                    if request(method='GET', path='/')[0] in (200, 503):
                        break
                except OSError:
                    pass
                time.sleep(.05)
            else:
                raise RuntimeError('Connector PHP test server did not start')
            yield request
        finally:
            process.terminate()
            process.wait(timeout=10)


def command(action, **fields):
    return {'protocol': PROTOCOL, 'action': action, **fields}


def result(request, action, **fields):
    code, body, headers = request(command(action, **fields))
    return code, json.loads(body), headers


with tempfile.TemporaryDirectory(prefix='lifeos-connector-') as temporary:
    temp = Path(temporary)
    project = temp / 'project'
    shutil.copytree(ROOT / 'integrations/website-connector', project)
    private = temp / 'private'
    private.mkdir()
    database = private / 'content.sqlite'
    token = secrets.token_hex(32)
    with server(project, database, token) as request:
        check(not database.exists(), 'Public empty page does not create the private database')
        check(request(command('manifest'), auth=False)[0] == 401, 'Missing bearer token rejected')
        check(request(command('manifest'), auth='wrong-' + token)[0] == 401, 'Wrong bearer token rejected')
        check(request(method='GET')[0] == 405, 'Admin endpoint rejects GET')
        check(request(command('manifest'), content_type='text/plain')[0] == 415, 'JSON content type required')
        check(request(raw=b'{bad json')[0] == 400, 'Malformed JSON rejected')
        check(request(raw=b'[]')[0] == 422, 'Top-level JSON array rejected')
        check(request(raw=b' ' * 262145)[0] == 413, 'Oversized request rejected')
        check(request({'protocol': 'wrong', 'action': 'manifest'})[0] == 422, 'Unknown protocol rejected')
        check(request(command('manifest', sql='DROP TABLE users'))[0] == 422, 'Arbitrary request fields rejected')
        code, manifest, headers = result(request, 'manifest')
        check(code == 200 and manifest['protocol'] == PROTOCOL, 'Manifest identifies the supported protocol')
        check(manifest['site']['name'] == 'Connector test site', 'Manifest uses configured site name')
        check([c['id'] for c in manifest['collections']] == ['pages', 'products'], 'Manifest declares generated admin collections')
        check(token not in json.dumps(manifest), 'Manifest does not expose its credential')
        check(headers.get('Cache-Control') == 'no-store' and headers.get('X-Content-Type-Options') == 'nosniff', 'Admin response is private and type-safe')
        code, page, _ = result(request, 'list', collection='pages', offset=0, limit=50)
        check(code == 200 and page['records'] == [] and page['total'] == 0, 'Empty content store lists no invented records')

        data = {'title': 'Private <title>', 'slug': 'first-page', 'body': '<script>alert("unsafe")</script>\nPlain text',
                'published': False, 'published_on': '2026-10-29'}
        code, created, _ = result(request, 'create', collection='pages', data=data)
        record = created['record']
        check(code == 200 and record['version'] == 1 and record['data'] == data, 'Create returns the actual saved record and version')
        check(request(method='GET', path='/?slug=first-page')[0] == 404, 'Unpublished record is not publicly readable')
        check(b'Private &lt;title&gt;' not in request(method='GET', path='/')[1], 'Unpublished title is omitted from public listing')
        for invalid_data, label in [
            ({**data, 'published': 1}, 'Boolean fields reject numeric coercion'),
            ({**data, 'published': None}, 'Boolean fields reject explicit null'),
            ({**data, 'published_on': '2026-02-30'}, 'Invalid calendar date rejected'),
            ({**data, 'slug': '../../private'}, 'Unsafe URL slug rejected'),
            ({**data, 'title': 'x' * 201}, 'Text length limit enforced'),
            ({**data, 'extra': 'unknown'}, 'Unknown content fields rejected'),
            ({'slug': 'missing-title'}, 'Missing required field rejected'),
        ]:
            check(result(request, 'create', collection='pages', data=invalid_data)[0] == 422, label)
        check(result(request, 'create', collection='pages', data=data)[0] == 409, 'Duplicate slug rejected')
        with closing(sqlite3.connect(database)) as db:
            check(db.execute('SELECT COUNT(*) FROM lifeos_records').fetchone()[0] == 1, 'Failed creates do not leave content rows')
            check(db.execute('SELECT COUNT(*) FROM lifeos_revisions').fetchone()[0] == 1, 'Failed creates do not leave revision rows')

        published = {**data, 'published': True}
        code, updated, _ = result(request, 'update', collection='pages', id=record['id'], version=1, data=published)
        check(code == 200 and updated['record']['version'] == 2, 'Update increments the optimistic version')
        code, public, _ = request(method='GET', path='/?slug=first-page')
        check(code == 200 and b'Private &lt;title&gt;' in public, 'Published record is read by the frontend helper')
        check(b'&lt;script&gt;' in public and b'<script>' not in public, 'Frontend escapes stored text rather than executing HTML')
        check(result(request, 'update', collection='pages', id=record['id'], version=1, data={**published, 'title': 'Stale overwrite'})[0] == 409, 'Stale update conflicts instead of overwriting')
        check(result(request, 'update', collection='pages', id='missing-id', version=1, data=published)[0] == 404, 'Missing record cannot be silently created by update')
        check(result(request, 'delete', collection='products', id=record['id'], version=2)[0] == 404, 'Wrong collection cannot delete another collection record')
        check(result(request, 'delete', collection='pages', id='../invalid', version=2)[0] == 422, 'Invalid record id rejected')
        check(result(request, 'delete', collection='pages', id=record['id'], version='2')[0] == 422, 'Version type is validated strictly')
        with closing(sqlite3.connect(database)) as db:
            check(db.execute('SELECT version FROM lifeos_records WHERE id=?', (record['id'],)).fetchone()[0] == 2, 'Rejected stale writes leave saved content untouched')
            check(db.execute('SELECT COUNT(*) FROM lifeos_revisions WHERE record_id=?', (record['id'],)).fetchone()[0] == 2, 'Only accepted writes produce revisions')

        for index in range(51):
            code, _, _ = result(request, 'create', collection='pages', data={**published, 'slug': f'page-{index}', 'title': f'Page {index}'})
            if code != 200:
                raise AssertionError('Pagination fixture could not be created')
        _, first, _ = result(request, 'list', collection='pages', offset=0, limit=50)
        _, second, _ = result(request, 'list', collection='pages', offset=50, limit=50)
        check(len(first['records']) == 50 and first['total'] == 52, 'First page respects the bounded record count')
        check(len(second['records']) == 2 and second['offset'] == 50, 'Second page returns the remaining records')
        check(not ({r['id'] for r in first['records']} & {r['id'] for r in second['records']}), 'Stable pagination does not repeat records between pages')
        check(result(request, 'list', collection='pages', offset=-1, limit=50)[0] == 422, 'Negative offset rejected')
        check(result(request, 'list', collection='pages', offset=0, limit=51)[0] == 422, 'Unbounded list request rejected')
        check(result(request, 'list', collection='unknown', offset=0, limit=50)[0] == 422, 'Unknown collection rejected')

        product = {'name': 'Test product', 'sku': 'SKU-1', 'description': '', 'price': 0, 'published': True}
        code, saved_product, _ = result(request, 'create', collection='products', data=product)
        check(code == 200 and saved_product['record']['data']['price'] == 0, 'Number and boolean fields preserve zero price and true')
        check(b'Test product' in request(method='GET', path='/')[1], 'Published product is read by the frontend helper')
        check(result(request, 'create', collection='products', data={**product, 'sku': 'sku-1'})[0] == 409, 'Product SKU uniqueness is case insensitive')
        check(result(request, 'create', collection='products', data={**product, 'sku': 'SKU-2', 'price': -1})[0] == 422, 'Negative product price rejected')
        check(result(request, 'create', collection='products', data={**product, 'sku': 'SKU-2', 'price': '20'})[0] == 422, 'Numeric field rejects string coercion')
        check(result(request, 'create', collection='products', data={**product, 'sku': 'SKU-2', 'price': None})[0] == 422, 'Required numeric field rejects explicit null')

        check(result(request, 'delete', collection='pages', id=record['id'], version=1)[0] == 409, 'Stale delete conflicts')
        code, deleted, _ = result(request, 'delete', collection='pages', id=record['id'], version=2)
        check(code == 200 and deleted['deleted'] is True, 'Delete reports the actual accepted operation')
        check(request(method='GET', path='/?slug=first-page')[0] == 404, 'Deleted page disappears from the public frontend')
        with closing(sqlite3.connect(database)) as db:
            revisions = db.execute('SELECT version,action,previous_data,current_data FROM lifeos_revisions WHERE record_id=? ORDER BY id', (record['id'],)).fetchall()
            check([r[1] for r in revisions] == ['create', 'update', 'delete'], 'Content revisions preserve the accepted change sequence')
            check(revisions[-1][0] == 3 and revisions[-1][2] is not None and revisions[-1][3] is None, 'Delete revision retains previous content for operator recovery')
            check(db.execute('PRAGMA integrity_check').fetchone()[0] == 'ok', 'Private content store retains SQLite integrity')

    # Exercise the real HTTP account flow without a shared administrative token.
    account_db = private / 'accounts.sqlite'
    account_env = dict(os.environ, LIFEOS_CONNECTOR_DB=str(account_db))
    provision = subprocess.run([os.environ.get('LIFEOS_QA_PHP', 'php'), str(project / 'bin/account.php'), 'set', 'client', 'viewer', 'pages'],
                               env=account_env, capture_output=True, text=True, check=True)
    password = next(line.removeprefix('Password: ') for line in provision.stdout.splitlines() if line.startswith('Password: '))
    with server(project, account_db, '') as request:
        code, body, _ = request(command('login', username='client', password=password), auth=False)
        login = json.loads(body)
        check(code == 200 and login['expires_at'] > time.time(), 'Username/password login issues a bounded HTTP session without a shared token')
        session_token = login['token']
        check(password not in body.decode(), 'Login response never returns the password')
        code, body, _ = request(command('manifest'), auth=session_token)
        manifest = json.loads(body)
        check(code == 200 and [c['id'] for c in manifest['collections']] == ['pages'], 'HTTP manifest includes only account-assigned collections')
        check(manifest['collections'][0]['capabilities'] == ['list'], 'HTTP manifest reflects viewer permissions')
        check(request(command('create', collection='pages', data={'title': 'Forbidden', 'slug': 'forbidden'}), auth=session_token)[0] == 403, 'Direct HTTP writes cannot bypass viewer permissions')
        check(request(command('list', collection='products'), auth=session_token)[0] == 403, 'Direct HTTP access cannot bypass collection permissions')
        check(request(command('logout'), auth=session_token)[0] == 200, 'Logout revokes the website HTTP session')
        check(request(command('manifest'), auth=session_token)[0] == 401, 'A logged-out bearer token cannot be reused')
        for _ in range(5):
            check(request(command('login', username='client', password='wrong'), auth=False)[0] == 401, 'Incorrect website password rejected over HTTP')
        check(request(command('login', username='client', password=password), auth=False)[0] == 429, 'HTTP endpoint rate limits repeated failures')
        with closing(sqlite3.connect(account_db)) as db:
            check(db.execute('SELECT password_hash FROM lifeos_users').fetchone()[0] != password, 'Website stores a password hash')
            db.execute('DELETE FROM lifeos_login_limits')
            db.commit()
        code, body, _ = request(command('login', username='client', password=password), auth=False)
        expired_token = json.loads(body)['token']
        with closing(sqlite3.connect(account_db)) as db:
            rows = db.execute('SELECT token_hash FROM lifeos_sessions').fetchall()
            check(all(row[0] != expired_token for row in rows), 'Website stores only token digests')
            db.execute('UPDATE lifeos_sessions SET expires_at=0')
            db.commit()
        check(request(command('manifest'), auth=expired_token)[0] == 401, 'Expired HTTP sessions are rejected by the server')

    with server(project, private / 'no-https.sqlite', token, allow_http=False) as request:
        check(request(command('manifest'))[0] == 403, 'Production mode rejects HTTP even on loopback')
        check(request(command('manifest'), headers={'X-Forwarded-Proto': 'https'})[0] == 403, 'Untrusted forwarding header does not bypass HTTPS requirement')
    unsafe = project / 'public' / 'must-not-exist.sqlite'
    with server(project, unsafe, token) as request:
        code, body, _ = request(command('manifest'))
        check(code == 503 and not unsafe.exists(), 'Content store inside the public document root is refused')
        check(str(unsafe).encode() not in body and token.encode() not in body, 'Configuration errors do not expose storage paths or tokens')
    with server(project, private / 'short-token.sqlite', 'short-token') as request:
        check(request(command('manifest'))[0] == 503, 'Short configured credentials are refused')

print(f'{CHECKS} website connector checks passed.')

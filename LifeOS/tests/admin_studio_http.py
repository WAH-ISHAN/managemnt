"""Complete local Admin Studio flows through real HTTP, SQLite and uploads."""
import base64, http.cookiejar, io, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request, zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
checks = 0
def check(condition, label):
    global checks
    assert condition, label
    checks += 1

with tempfile.TemporaryDirectory(prefix='lifeos-studio-http-') as temporary:
    data = Path(temporary)
    project = data/'project'
    for folder in ['app', 'public']: shutil.copytree(root/folder, project/folder)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0)); port = listener.getsockname()[1]
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), urllib.request.ProxyHandler({}))
    base = f'http://127.0.0.1:{port}'
    def request(path, fields=None, raw=None, headers=None):
        body = raw if raw is not None else urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            with opener.open(urllib.request.Request(base+path, body, headers or {}), timeout=15) as response: return response.status, response.read(), response.headers, response.url
        except urllib.error.HTTPError as error: return error.code, error.read(), error.headers, error.url
    def upload(site, filename, content):
        boundary = 'studio-' + os.urandom(12).hex()
        body = f'--{boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + f'\r\n--{boundary}--\r\n'.encode()
        return request(f'/web-admin/{site}/media', raw=body, headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    db = None
    with (data/'server.log').open('w') as log:
        server = subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'), '-S', f'127.0.0.1:{port}', '-t','public','public/index.php'], cwd=project, env=dict(os.environ,LIFEOS_DATA_DIR=temporary), stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    if request('/health')[0] == 200: break
                except OSError: pass
                time.sleep(.1)
            code, page, _, _ = request('/')
            csrf = re.search(rb'name="_csrf" value="([a-f0-9]+)"',page).group(1).decode()
            request('/welcome',{'_csrf':csrf,'name':'Studio acceptance owner','institution':'','areas[]':'business'})
            for i in range(2):
                result = request('/web-admin/workspace',{'_csrf':csrf,'name':f'Studio site {i}','base_url':f'https://studio{i}.example.com','business_name':f'Business {i}'})
                check(result[0] == 200 and b'Everything, in one place.' in result[1], 'Create workspace opens the combined admin dashboard')
            db = sqlite3.connect(data/'lifeos.db'); db.row_factory = sqlite3.Row
            site, other = [row[0] for row in db.execute('SELECT id FROM website_profiles ORDER BY id')]
            code, page, _, _ = request('/web-admin')
            check(code == 200 and b'Admin Studio' in page and b'Studio site 0' in page and b'Studio site 1' in page, 'LifeOS has a combined Admin Studio option and all workspaces')
            sections = ['homepage','projects','weddings','services','about','creative_pool','pages','blog','navigation','seo','branding','customers','inquiries','tasks','invoices','campaigns','integrations']
            for section in sections:
                code, page, _, _ = request(f'/web-admin/{site}?section={section}')
                check(code == 200 and f'/records/{section}'.encode() in page and b'Title / name' in page, 'Functional section renders: ' + section)
            for section in ['media','activity','trash','settings','access','connected']:
                check(request(f'/web-admin/{site}?section={section}')[0] == 200, 'Workspace section renders: ' + section)
            check(request(f'/web-admin/{site}?section=unknown')[0] == 422, 'Unknown section rejected')
            check(request(f'/web-admin/{site}/records/pages',{'title':'CSRF','status':'draft','fields[slug]':'csrf'})[0] == 419, 'Studio writes require CSRF')
            fields = {'_csrf':csrf,'title':'<script>Studio title</script>','status':'draft','fields[slug]':'real-page','fields[body]':'Saved body content'}
            code, page, _, _ = request(f'/web-admin/{site}/records/pages',fields)
            check(code == 200 and b'&lt;script&gt;Studio title&lt;/script&gt;' in page and b'<script>Studio title</script>' not in page,'Save persists a record and safely escapes user-authored titles')
            record = dict(db.execute("SELECT * FROM website_studio_records WHERE website_id=? AND section='pages'",(site,)).fetchone())
            check(json.loads(record['data'])['body'] == 'Saved body content', 'HTTP save writes real SQLite content')
            fields.update(record_id=record['id'],version=1,title='Edited page',status='ready')
            check(request(f'/web-admin/{site}/records/pages',fields)[0] == 200,'Edit record round trip succeeds')
            check(request(f'/web-admin/{site}/records/pages',fields)[0] == 422,'Stale browser edit rejected')
            check(request(f'/web-admin/{other}/records/pages',fields)[0] == 422,'Cross-workspace save rejected')
            check(request(f'/web-admin/{site}?section=pages&q=Edited&status=ready')[0] == 200,'Search and status filter round trip succeeds')
            check(request(f'/web-admin/{other}?section=pages&edit={record["id"]}')[0] == 422,'Cross-workspace editor denied')
            for invalid in [{'fields[slug]':'wrong/slug'}, {'status':'invented'}, {'fields[unknown]':'bad'}, {'title[]':'bad','title':''}]:
                bad={'_csrf':csrf,'title':'Invalid','status':'draft','fields[slug]':'new-slug',**invalid}
                check(request(f'/web-admin/{site}/records/pages',bad)[0] == 422,'Malformed record rejected')
            check(request(f'/web-admin/{site}/records/pages',{'_csrf':csrf,'title':'Duplicate','status':'draft','fields[slug]':'real-page'})[0] == 422,'Duplicate page slug rejected')
            check(request(f'/web-admin/{site}/records/{record["id"]}/trash',{'_csrf':csrf,'version':2})[0] == 200,'Trash action completes')
            check(b'Edited page' not in request(f'/web-admin/{site}?section=pages')[1],'Trashed record removed from active list')
            check(b'Edited page' in request(f'/web-admin/{site}?section=trash')[1],'Trash retains record for recovery')
            check(request(f'/web-admin/{site}/records/{record["id"]}/restore',{'_csrf':csrf,'version':3})[0] == 200,'Restore completes')
            check(db.execute('SELECT version FROM website_studio_records WHERE id=?',(record['id'],)).fetchone()[0] == 4,'Restore increments optimistic version')
            check(request(f'/web-admin/{site}/records/invoices',{'_csrf':csrf,'title':'INV-HTTP','status':'sent','fields[reference]':'INV-HTTP','fields[customer]':'Test customer','fields[amount]':'123.45'})[0] == 200,'Invoice entry saves')
            check(b'Rs. 123.45' in request(f'/web-admin/{site}')[1], 'Dashboard invoice balance reflects the saved invoice')
            check(db.execute('SELECT COUNT(*) FROM expenses').fetchone()[0] == 0,'Invoice record does not move Money account funds')
            png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS8AAAAASUVORK5CYII=')
            check(upload(site,'asset.png',png)[0] == 200,'Real multipart media upload succeeds')
            media = dict(db.execute('SELECT * FROM website_studio_media WHERE website_id=?',(site,)).fetchone())
            code, binary, headers, _ = request(f'/web-admin/{site}/media/{media["id"]}')
            check(code == 200 and binary == png and headers.get('Content-Type') == 'image/png','Downloaded media exactly matches original bytes')
            check(request(f'/web-admin/{other}/media/{media["id"]}')[0] == 422,'Media isolated by website')
            check(upload(site,'bad.svg',b'<svg xmlns="http://www.w3.org/2000/svg"><script>bad</script></svg>')[0] == 422,'Executable SVG upload rejected')
            check(db.execute('SELECT COUNT(*) FROM website_studio_media').fetchone()[0] == 1,'Rejected media does not create orphan database entries')
            code, archive, headers, _ = request(f'/web-admin/{site}/export')
            with zipfile.ZipFile(io.BytesIO(archive)) as bundle:
                exported = json.loads(bundle.read('workspace.json'))
                check(code == 200 and exported['format'] == 'lifeos-admin-studio-v1' and len(exported['records']) == 2,'ZIP export contains this workspace real records')
                check(bundle.read(f'media/{media["id"]}-asset.png') == png,'ZIP export includes actual original media')
                check('connector_url' not in exported['website'] and b'password' not in bundle.read('workspace.json'),'Export excludes connection credentials')
            check(request(f'/websites/{site}/remove',{'_csrf':csrf})[0] == 422,'Profile deletion protects local Studio history')
            check(request(f'/web-admin/{site}/remote/save',{'_csrf':csrf,'collection':'pages','fields[title]':'No live session'})[0] == 422,'Disconnected studio cannot pretend to save live content')
            check(db.execute('PRAGMA integrity_check').fetchone()[0] == 'ok','Final database remains healthy')
            print(f'{checks} Admin Studio HTTP end-to-end checks passed.')
        finally:
            if db: db.close()
            server.terminate(); server.wait(timeout=15)

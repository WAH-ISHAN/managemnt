"""Actual browser/HTTP workspace handoff, using disposable paired local profiles."""
import json, os, socket, sqlite3, subprocess, tempfile, time, uuid
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tests/.tmp' / ('workspace-exchange-http-' + uuid.uuid4().hex)
OUT.mkdir(parents=True)
PHP = os.environ.get('LIFEOS_QA_PHP', 'php')
checks = []

def check(ok, label):
    if not ok:
        raise AssertionError(label)
    checks.append(label)

with tempfile.TemporaryDirectory(prefix='lifeos-workspace-browser-') as temporary:
    base = Path(temporary)
    data, shared = base / 'data', base / 'shared'
    data.mkdir(); shared.mkdir()
    fixture = base / 'fixture.php'
    fixture.write_text('''<?php
require ROOT_PLACEHOLDER.'/tests/bootstrap.php';require ROOT_PLACEHOLDER.'/app/Core/Backup.php';define('DATA',DATA_PLACEHOLDER);
$dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules')));
Db::init(DATA.'/lifeos.db');Migrator::run($dirs);Db::set('onboarding_complete','1');Db::set('name','Snapshot browser QA');
$a=DeviceSyncService::identity();$bpair=sodium_crypto_box_keypair();$bpub=base64_encode(sodium_crypto_box_publickey($bpair));$bid=hash('sha256',sodium_crypto_box_publickey($bpair));DeviceSyncService::pair('Second test computer',$bpub);
Db::run("INSERT INTO tasks(title,source) VALUES('Incoming browser task','planner')");
WorkspaceExchange::configure(['enabled'=>1,'folder'=>SHARED_PLACEHOLDER,'peer'=>$bid,'minutes'=>60,'share_workspace'=>'yes','fingerprint'=>$bid]);$file=WorkspaceExchange::send();
Db::close();rename(DATA.'/lifeos.db',DATA.'/source.db');file_put_contents(DATA.'/.platform-device-key',$bpair);
Db::init(DATA.'/lifeos.db');Migrator::run($dirs);Db::set('onboarding_complete','1');Db::set('name','Workspace browser QA');DeviceSyncService::pair('Source test computer',$a['public']);
Db::run("INSERT INTO tasks(title,source) VALUES('Local browser task','planner')");
WorkspaceExchange::configure(['enabled'=>1,'folder'=>SHARED_PLACEHOLDER,'peer'=>$a['id'],'minutes'=>60,'share_workspace'=>'yes','fingerprint'=>$a['id']]);
echo json_encode(['file'=>$file,'peer'=>$a['id']]);
'''.replace('ROOT_PLACEHOLDER', json.dumps(str(ROOT).replace('\\', '/')))
        .replace('DATA_PLACEHOLDER', json.dumps(str(data).replace('\\', '/')))
        .replace('SHARED_PLACEHOLDER', json.dumps(str(shared).replace('\\', '/'))), encoding='utf-8')
    seeded = subprocess.run([PHP, str(fixture)], capture_output=True, text=True, check=True)
    meta = json.loads(seeded.stdout)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    url = f'http://127.0.0.1:{port}'
    log = (OUT / 'server.log').open('w', encoding='utf-8')
    server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=ROOT,
        env=dict(os.environ, LIFEOS_DATA_DIR=str(data)), stdout=log, stderr=log,
        creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    try:
        with sync_playwright() as pw:
            browser = pw.chromium.launch(headless=True)
            page = browser.new_page(viewport={'width':1280, 'height':900}, reduced_motion='reduce')
            errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            for attempt in range(100):
                try:
                    if page.request.get(url+'/health').status == 200: break
                except Exception: time.sleep(.1)
            response=page.goto(url+'/platform');page.wait_for_load_state('networkidle')
            check(response.status == 200, 'Devices page opens with paired snapshot controls')
            section=page.locator('#workspace-delivery')
            check(section.get_by_role('heading',name='Continue on another computer').count()==1,'Task-focused handoff interface rendered')
            check(section.get_by_text('Source test computer',exact=True).count()>0,'Received snapshot identified by paired computer')
            csrf=page.locator('input[name="_csrf"]').first.input_value()
            check(page.request.post(url+'/platform/workspace/apply',form={'confirmation':'REPLACE'}).status==419,'Replacement rejects missing CSRF')
            check(page.request.post(url+'/platform/workspace/apply',form={'_csrf':csrf,'confirmation':'REPLACE'}).status==422,'Replacement requires prior authenticated review')
            page.screenshot(path=str(OUT/'workspace-delivery-desktop.png'),full_page=True)
            section.get_by_role('button',name='Review received workspace').click();expect(page.get_by_role('heading',name='Review workspace replacement',exact=True)).to_be_visible();page.wait_for_load_state('networkidle')
            check(page.get_by_role('heading',name='Review workspace replacement',exact=True).count()==1,'Actual review action authenticates and decrypts snapshot')
            check(page.evaluate('''() => document.querySelector('#main h1').getBoundingClientRect().top >= document.querySelector('.app-toolbar').getBoundingClientRect().bottom + 8'''),'Review heading is unobscured after normal navigation')
            page.screenshot(path=str(OUT/'workspace-review-desktop-viewport.png'))
            check(page.get_by_text('Tasks',exact=True).count()==1,'Review displays record group changes')
            check(page.get_by_text('Changed',exact=True).count()==1,'Review distinguishes changed records')
            check('automatic workspace sharing' in page.locator('#main').inner_text(),'Review explains paused jobs before replacement')
            csrf=page.locator('input[name="_csrf"]').first.input_value()
            check(page.request.post(url+'/platform/workspace/apply',form={'_csrf':csrf,'confirmation':'yes'}).status==422,'Server independently rejects inexact confirmation')
            page.screenshot(path=str(OUT/'workspace-review-desktop.png'),full_page=True)
            page.set_viewport_size({'width':390,'height':844})
            check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Replacement review fits a narrow screen')
            check(page.evaluate('''() => document.querySelector('#main h1').getBoundingClientRect().top >= document.querySelector('.app-toolbar').getBoundingClientRect().bottom + 8'''),'Narrow review heading stays below toolbar')
            page.screenshot(path=str(OUT/'workspace-review-narrow-viewport.png'))
            page.screenshot(path=str(OUT/'workspace-review-narrow.png'),full_page=True)
            changes=page.locator('#main .table-wrap')
            changes.evaluate('(el)=>{el.scrollLeft=el.scrollWidth;el.scrollIntoView({block:"center",behavior:"instant"})}')
            check(changes.evaluate('''el=>{const box=el.getBoundingClientRect();return [...el.querySelectorAll('thead th')].slice(-2).every(cell=>{const r=cell.getBoundingClientRect();return r.left>=box.left&&r.right<=box.right+1})}'''),'Narrow changes and removals are readable through horizontal table scrolling')
            page.screenshot(path=str(OUT/'workspace-review-narrow-changes-viewport.png'))
            oldtab=browser.new_page();oldtab.goto(url+'/platform');oldcsrf=oldtab.locator('input[name="_csrf"]').first.input_value()
            page.locator('input[name=confirmation]').fill('REPLACE')
            page.get_by_role('button',name='Replace with reviewed workspace').click();expect(page.locator('#workspace-delivery')).to_be_visible();page.wait_for_load_state('networkidle')
            with sqlite3.connect(data/'lifeos.db') as db:
                check(db.execute('SELECT title FROM tasks').fetchall()==[('Incoming browser task',)],'Confirmed browser action restores exactly the reviewed records')
                config=json.loads(db.execute("SELECT value FROM settings WHERE key='workspace_exchange'").fetchone()[0])
                check(config['enabled'] is False,'Replacement pauses onward automatic sharing')
                check(db.execute('SELECT COUNT(*) FROM platform_workspace_receipts').fetchone()[0]==1,'Replacement receipt prevents replay')
                check(oldtab.request.post(url+'/platform/history/clear',form={'_csrf':oldcsrf}).status==419,'A different stale browser session cannot submit pre-replacement writes')
                for lang,script in [('si','[\\u0d80-\\u0dff]'),('ta','[\\u0b80-\\u0bff]')]:
                    db.execute("INSERT INTO settings(key,value) VALUES('locale',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[lang]);db.commit()
                    page.goto(url+'/platform');page.wait_for_load_state('networkidle')
                    check(page.locator('#workspace-delivery').evaluate('(el,p)=>new RegExp(p).test(el.innerText)',script),lang+' handoff controls translated')
                    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),lang+' handoff controls fit narrow screen')
                    page.screenshot(path=str(OUT/f'workspace-{lang}-narrow.png'),full_page=True)
            db.close()
            check(not errors,'No JavaScript errors in handoff/review flows')
            browser.close()
    finally:
        server.terminate();server.wait(timeout=10);log.close()
(OUT/'results.json').write_text(json.dumps({'checks':checks},indent=2),encoding='utf-8')
print(f'{len(checks)} workspace exchange HTTP/browser checks passed. Evidence: {OUT}')

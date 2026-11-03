"""Exercise every module landing page in all supported languages on disposable data."""
import json,os,socket,sqlite3,subprocess,time,urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'tests/.tmp/localization-ui';OUT.mkdir(parents=True,exist_ok=True)
DATA=OUT/('data-'+str(time.time_ns()));DATA.mkdir()
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
base=f'http://127.0.0.1:{port}'
checks=[];errors=[];findings={};metrics={}
log=(OUT/'server.log').open('w',encoding='utf-8')
server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=ROOT,env=dict(os.environ,LIFEOS_DATA_DIR=str(DATA)),stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
def check(ok,label):
    if not ok:raise AssertionError(label)
    checks.append(label)
try:
    for _ in range(100):
        try:
            urllib.request.build_opener(urllib.request.ProxyHandler({})).open(base+'/health',timeout=2).close();break
        except OSError:time.sleep(.1)
    db=sqlite3.connect(DATA/'lifeos.db')
    db.executemany('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[('onboarding_complete','1'),('name','Localization QA')]);db.execute("INSERT INTO tasks(title,due) VALUES ('Save','2099-01-05')");db.commit()
    routes=[]
    for manifest in sorted((ROOT/'app/Modules').glob('*/module.json')):
        m=json.loads(manifest.read_text(encoding='utf-8'))
        if (manifest.parent/'routes.php').exists() and m['route']!='/welcome':routes.append(m['route'])
    with sync_playwright() as pw:
        browser=pw.chromium.launch(headless=True)
        page=browser.new_page(viewport={'width':1365,'height':900},reduced_motion='reduce')
        page.on('pageerror',lambda e:errors.append(str(e)))
        for lang in ['en','si','ta']:
            db.execute("UPDATE settings SET value=? WHERE key='locale'",[lang]);db.execute("INSERT OR IGNORE INTO settings(key,value) VALUES ('locale',?)",[lang]);db.commit()
            for route in routes:
                response=page.goto(base+route);page.wait_for_load_state('networkidle')
                check(response.status<400,f'{lang} {route} opens')
                check(page.locator('html').get_attribute('lang')==lang,f'{lang} {route} language')
                check(page.evaluate('typeof window.lifeosT')=='function',f'{lang} {route} client translator loaded')
                text=page.locator('#main').inner_text()
                check('\ufffd' not in text,f'{lang} {route} valid Unicode')
                page.set_viewport_size({'width':390,'height':900})
                check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),f'{lang} {route} fits narrow viewport')
                page.set_viewport_size({'width':1365,'height':900})
                if lang!='en':
                    alphabet='[\\u0d80-\\u0dff]' if lang=='si' else '[\\u0b80-\\u0bff]'
                    check(page.locator('#main').evaluate('(el,pattern)=>new RegExp(pattern).test(el.innerText)',alphabet),f'{lang} {route} translated content')
                    findings[lang+route]=page.locator('#main').evaluate('''el=>{const out=[];const walk=document.createTreeWalker(el,NodeFilter.SHOW_TEXT);let n;while(n=walk.nextNode()){const s=n.textContent.trim();if(/[A-Za-z]{3}/.test(s)&&n.parentElement.getClientRects().length&&!n.parentElement.closest('script,style,code,pre'))out.push(s)}return [...new Set(out)]}''')
                if route in ['/','/planner','/salary','/uni','/settings']:
                    label=route.strip('/').replace('/','-') or 'home'
                    for width in [1365,390]:
                        page.set_viewport_size({'width':width,'height':900});page.screenshot(path=str(OUT/f'{lang}-{label}-{width}.png'),full_page=True)
                        extent=page.evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})')
                        metrics[f'{lang}-{label}-{width}']=extent
                        check(extent['scroll']<=width+1,f'{lang} {route} fits {width}')
                    page.set_viewport_size({'width':1365,'height':900})
            page.goto(base+'/planner?view=tasks')
            check(page.get_by_text('Save',exact=True).count()>0,f'{lang} user task title stays unchanged')
        check(not errors,'No JavaScript errors')
        browser.close()
    (OUT/'results.json').write_text(json.dumps({'checks':checks,'errors':errors,'metrics':metrics,'english_review':findings},ensure_ascii=False,indent=2),encoding='utf-8')
    print(f'{len(checks)} localization UI checks passed. Evidence: {OUT}')
finally:
    server.terminate();server.wait(timeout=10);log.close()

"""Commercial enforcement through real PHP routes, with disposable signed fixtures."""
import json,os,re,shutil,socket,subprocess,time,urllib.request,urllib.error,urllib.parse,uuid
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'tests/.tmp'/('license-http-'+uuid.uuid4().hex);HOST=OUT/'host';DATA=OUT/'data';HOST.mkdir(parents=True);DATA.mkdir()
shutil.copytree(ROOT/'app',HOST/'app');(HOST/'public').mkdir();shutil.copy2(ROOT/'public/index.php',HOST/'public/index.php')
# Assets are read-only test resources; use a junction without requiring symlink privileges.
subprocess.run(['powershell','-NoProfile','-Command',f"New-Item -ItemType Junction -Path '{HOST / 'public/assets'}' -Target '{ROOT / 'public/assets'}' | Out-Null"],check=True,capture_output=True)
fixture=json.loads(subprocess.check_output(['node','-e',"const c=require('node:crypto');const p=c.generateKeyPairSync('ed25519');const t=Math.floor(Date.now()/1000);let v={v:1,iss:'https://orean.tech',aud:'lifeos-desktop',sub:'qa-user',activation:'qa-activation',device:'a'.repeat(64),iat:t,exp:t+86400,permissions:['planner'],name:'QA user',email:'qa@example.invalid'};const e=Buffer.from(JSON.stringify(v)).toString('base64url');console.log(JSON.stringify({publicKey:p.publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('base64'),lease:e+'.'+c.sign(null,Buffer.from(e),p.privateKey).toString('base64url')}))"],text=True))
config=HOST/'app/Core/license-config.json';config.write_text(json.dumps({'required':True,'publicKey':fixture['publicKey'],'server':'https://orean.tech'}),encoding='utf-8')
with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
base=f'http://127.0.0.1:{port}';log=(OUT/'server.log').open('w',encoding='utf-8');server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=HOST,env=dict(os.environ,LIFEOS_DATA_DIR=str(DATA),LIFEOS_DEVICE_HASH='a'*64),stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0));checks=[]
def check(ok,label):
    assert ok,label
    checks.append(label)
try:
    for _ in range(100):
        try:urllib.request.urlopen(base+'/health',timeout=2).close();break
        except OSError:time.sleep(.1)
    with sync_playwright()as pw:
        browser=pw.chromium.launch(headless=True);page=browser.new_page(viewport={'width':430,'height':800});page.goto(base+'/planner');check(page.url.endswith('/account'),'Commercial build sends unactivated users to account screen');check(page.get_by_role('heading',name='Sign in to LifeOS').is_visible(),'Activation screen contains sign in');page.screenshot(path=str(OUT/'account-required.png'),full_page=True)
        response=page.request.post(base+'/account/login',form={'email':'qa@example.invalid','password':'test'});check(response.status==419,'Account mutations require local CSRF token')
        account=DATA/'account/activation.json';account.write_text(json.dumps({'user':{'id':'qa-user','email':'qa@example.invalid','name':'QA user'},'token':'synthetic-encrypted-token','lease':fixture['lease'],'refresh_after':time.time()+3600}),encoding='utf-8')
        response=page.goto(base+'/planner');check(response.status==200 and '/planner' in page.url,'Signed offline activation permits its included module');page.screenshot(path=str(OUT/'licensed-planner.png'),full_page=True)
        response=page.goto(base+'/salary');check(response.status==403,'Money access denied when not included');response=page.goto(base+'/assistant');check(response.status==403,'AI access denied when not included')
        check((DATA/'account/commercial').is_file(),'Commercial enforcement persisted outside application payload')
        config.write_text(json.dumps({'required':False,'publicKey':fixture['publicKey'],'server':'https://orean.tech'}),encoding='utf-8');account.write_text('{}',encoding='utf-8');page.goto(base+'/planner');check(page.url.endswith('/account'),'Signing out or a personal update cannot clear commercial enforcement')
        check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Account screen fits compact Windows panel');browser.close()
    (OUT/'results.json').write_text(json.dumps(checks,indent=2),encoding='utf-8');print(f'{len(checks)} commercial HTTP/browser checks passed. Evidence: {OUT}')
finally:server.terminate();server.wait(timeout=10);log.close()

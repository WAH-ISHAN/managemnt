"""Account settings and chat stay separate; OAuth state fixtures never impersonate a real login."""
import json,os,socket,sqlite3,subprocess,time,urllib.request,uuid
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'tests/.tmp'/('ai-account-ui-'+uuid.uuid4().hex);OUT.mkdir(parents=True)
with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
base=f'http://127.0.0.1:{port}';log=(OUT/'server.log').open('w',encoding='utf-8');server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=ROOT,env=dict(os.environ,LIFEOS_DATA_DIR=str(OUT/'data')),stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0));checks=[];errors=[]
def check(ok,label):
 assert ok,label
 checks.append(label)
try:
 for _ in range(100):
  try:urllib.request.urlopen(base+'/health',timeout=2).close();break
  except OSError:time.sleep(.1)
 with sync_playwright()as pw:
  browser=pw.chromium.launch(headless=True);page=browser.new_page(viewport={'width':440,'height':850});page.on('pageerror',lambda e:errors.append(str(e)))
  page.add_init_script("window.chrome={webview:{postMessage:m=>(window.__messages??=[]).push(m),addEventListener:()=>{},removeEventListener:()=>{}}}")
  page.goto(base+'/account/ai');expect(page.get_by_role('status')).to_contain_text('No AI account');check(page.locator('input[type=password],select,input[type=checkbox]').count()==0,'Dedicated connection settings has no password, model or API-key form')
  page.get_by_role('button',name='Connect my account',exact=True).click();expect(page.get_by_role('status')).to_contain_text('Waiting for your secure sign-in')
  files=list((OUT/'data/ai/jobs').glob('*.json'));check(len(files)==1,'Connect creates exactly one profile-scoped native job');file=files[0];job=json.loads(file.read_text());check(job['kind']=='account_connect' and 'question'not in job,'Connection job has no personal records or question')
  expect(page.get_by_role('button',name='Connect my account',exact=True)).to_be_disabled();check(page.evaluate("window.__messages.some(m=>m.type==='lifeos-ai-job')"),'Account screen requests native worker through bridge')
  job.update(status='running',authorization={'url':'https://opencode.ai/console/device','instructions':'Enter code: QA-CODE','expires_at':time.time()+60});file.write_text(json.dumps(job),encoding='utf-8');expect(page.get_by_text('Enter code: QA-CODE',exact=True)).to_be_visible();page.get_by_role('button',name='Open secure sign-in',exact=True).click();check(page.evaluate("window.__messages.some(m=>m.type==='lifeos-ai-auth-open')"),'Only explicit Open secure sign-in opens browser bridge')
  page.screenshot(path=str(OUT/'waiting-sign-in.png'),full_page=True)
  page.get_by_role('button',name='Cancel sign-in',exact=True).click();page.wait_for_function("document.querySelector('[data-account-cancel]').disabled===false");check(file.with_suffix('.cancel').is_file(),'Cancel requests native OAuth cancellation')
  job['status']='failed';job['error']='private access_token diagnostic';job.pop('authorization');file.write_text(json.dumps(job),encoding='utf-8');expect(page.get_by_role('status')).to_contain_text('could not finish');check('private access_token'not in page.locator('body').inner_text(),'Account errors never expose credential diagnostics')
  page.screenshot(path=str(OUT/'failed-sign-in.png'),full_page=True)
  (OUT/'data/ai/account.json').write_text(json.dumps({'connected':True,'updated_at':time.time(),'private_token':'secret-fixture'}),encoding='utf-8');job['status']='complete';file.write_text(json.dumps(job),encoding='utf-8');page.reload();expect(page.get_by_role('status')).to_contain_text('connected to this LifeOS profile');check('private_token'not in page.request.get(base+'/account/ai/state').text(),'Only safe account fields reach the browser')
  page.once('dialog',lambda d:d.accept());page.get_by_role('button',name='Disconnect',exact=True).click();expect(page.get_by_role('status')).to_contain_text('Checking your AI account');check(any(json.loads(f.read_text())['kind']=='account_disconnect'for f in (OUT/'data/ai/jobs').glob('*.json')),'Disconnect uses native credential API job')
  page.goto(base+'/assistant');check(page.locator('[data-ai-account]').count()==0 and 'OpenCode'not in page.locator('body').inner_text(),'Normal conversation stays separate from provider connection settings')
  page.route('**/assistant/state',lambda route:route.fulfill(json={'thread':'fixture','messages':[],'accountRequired':True,'accountConnected':False}))
  page.reload();expect(page.locator('[data-ai-connect]')).to_be_visible();page.locator('[name=message]').fill('Hello');expect(page.locator('.assistant-send')).to_be_disabled();page.locator('[data-ai-account-link]').click();check(page.evaluate("window.__messages.some(m=>m.type==='lifeos-assistant-open'&&m.route==='/account/ai')"),'Commercial chat directs the user to connect their own account before sending')
  for width in [390,760]:
   page.set_viewport_size({'width':width,'height':850});page.goto(base+'/account/ai');check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'AI account settings fit '+str(width));page.screenshot(path=str(OUT/f'account-{width}.png'),full_page=True)
  check(not errors,'No browser errors: '+str(errors));browser.close()
 (OUT/'results.json').write_text(json.dumps(checks,indent=2),encoding='utf-8');print(f'{len(checks)} AI account UI checks passed. Evidence: {OUT}')
finally:server.terminate();server.wait(timeout=10);log.close()

"""Work Roles and reviewed assistant actions through real browser/HTTP, disposable data.
AI responses are contract fixtures here. Live provider acceptance is recorded separately.
"""
import json,os,socket,sqlite3,subprocess,time,urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'tests/.tmp/roles-ai-ui';OUT.mkdir(parents=True,exist_ok=True);DATA=OUT/str(time.time_ns());DATA.mkdir()
with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
base=f'http://127.0.0.1:{port}';checks=[];errors=[]
def check(ok,label):
    assert ok,label
    checks.append(label)
log=(OUT/'server.log').open('w',encoding='utf-8');server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=ROOT,env=dict(os.environ,LIFEOS_DATA_DIR=str(DATA)),stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
try:
    for _ in range(100):
        try:urllib.request.build_opener(urllib.request.ProxyHandler({})).open(base+'/health',timeout=2).close();break
        except OSError:time.sleep(.1)
    db=sqlite3.connect(DATA/'lifeos.db');db.executemany('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[('name','QA user'),('onboarding_complete','1')]);db.commit()
    with sync_playwright()as pw:
        browser=pw.chromium.launch(headless=True);page=browser.new_page(viewport={'width':1365,'height':900},reduced_motion='reduce');page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base+'/roles');page.locator('form[action="/roles/templates"] button').click();expect(page.locator('select[name=role] option')).to_have_count(6);check(db.execute('SELECT COUNT(*) FROM work_roles').fetchone()[0]==6,'Default role button creates six editable roles')
        count=db.execute('SELECT COUNT(*) FROM work_tasks').fetchone()[0];page.reload();check(db.execute('SELECT COUNT(*) FROM work_tasks').fetchone()[0]==count,'Today reload does not duplicate responsibilities')
        role=db.execute('SELECT id FROM work_roles ORDER BY id').fetchone()[0];task=db.execute('SELECT id FROM work_tasks WHERE role_id=? ORDER BY id',[role]).fetchone()[0]
        timer=page.locator('form[action="/roles/timer/start"]');timer.locator('select[name=task_id]').select_option(str(task));timer.locator('button').click();expect(page.locator('[data-role-timer]')).to_be_visible();check(db.execute('SELECT COUNT(*) FROM role_time_logs WHERE ended_at IS NULL').fetchone()[0]==1,'Role/task timer starts')
        page.locator('form[action="/roles/timer/stop"] button').click();expect(page.locator('[data-role-timer]')).to_have_count(0);check(db.execute('SELECT COUNT(*) FROM role_time_logs WHERE ended_at IS NULL').fetchone()[0]==0,'Role timer stops')
        status=page.locator(f'form[action="/roles/tasks/{task}/status"]');status.locator('select').select_option('done');status.locator('button').click();expect(page.get_by_text('Work task updated.',exact=True)).to_be_visible();check(db.execute('SELECT status,done_at FROM work_tasks WHERE id=?',[task]).fetchone()[0]=='done','Role task completion persists')
        daily=page.locator('form[action="/roles/daily"]');daily.locator('[name=did]').fill('Reviewed code');daily.locator('[name=blocked]').fill('Missing example');daily.locator('[name=next]').fill('Ask the team');daily.locator('button').click();expect(page.get_by_text('Daily work log saved.',exact=True)).to_be_visible();page.locator('form[action$="/lesson"] button').click();expect(page.get_by_text('Reflection linked to Lessons.',exact=True)).to_be_visible();check(db.execute('SELECT COUNT(*) FROM lessons').fetchone()[0]==1,'Daily log links to a learning/failure record')
        page.goto(base+'/roles?view=week');income=page.locator('form[action="/roles/income"]').first;income.locator('..').locator('summary').click();income.locator('input[name=amount]').fill('125.50');income.locator('button:not([type=button])').click();expect(page.get_by_text('Received income recorded once through Money.',exact=True)).to_be_visible();check(db.execute('SELECT amount FROM income').fetchone()[0]==125.5,'Confirmed role income reaches Money')
        page.goto(base+'/intelligence');check(page.get_by_role('heading',name='LifeOS assistant',exact=True).count()>0,'LifeOS chat UI opens')
        page.locator('form[action="/intelligence/models"] button').click();page.wait_for_url('**/intelligence?job=*');model_job=page.url.split('job=')[1];file=DATA/'ai/jobs'/f'{model_job}.json';job=json.loads(file.read_text(encoding='utf-8'));job['status']='complete';file.write_text(json.dumps(job),encoding='utf-8');(DATA/'ai/models.json').write_text(json.dumps({'fetched_at':time.time(),'models':[{'id':'opencode/test-free','name':'Test free model','input_cost':0,'output_cost':0}]}),encoding='utf-8');page.reload()
        form=page.locator('form[action="/intelligence/chat"]');form.locator('[name=question]').fill('Create a reviewed task');form.locator('[name=send_external]').check();check(not form.locator('[name=include_totals]').is_checked(),'Financial sharing defaults off');form.locator('button').click();page.wait_for_url(lambda url: 'job=' in str(url) and model_job not in str(url));chat_id=page.url.split('job=')[1];file=DATA/'ai/jobs'/f'{chat_id}.json';job=json.loads(file.read_text(encoding='utf-8'));check(not job['context'],'No financial/work records enter an unconsented request')
        before=db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0];job['status']='complete';job['answer']=json.dumps({'answer':'Review <script>unsafe()</script> safely','actions':[{'action':'add_task','title':'Review suggested task','due':'2099-01-05'}]});file.write_text(json.dumps(job),encoding='utf-8');page.reload();check(db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0]==before,'AI answer does not execute a write');check(page.locator('article script').count()==0,'Model answer is escaped')
        apply=page.locator('form[action$="/apply"]').first;apply.locator('[name=title]').fill('User-reviewed task');apply.locator('button:not([type=button])').click();expect(page.get_by_text('This action was already applied.',exact=True)).to_be_visible();check(db.execute('SELECT COUNT(*) FROM tasks WHERE title=?',['User-reviewed task']).fetchone()[0]==1,'Only confirmed reviewed task is created')
        for route,label in [('/roles','roles-today'),('/roles?view=week','roles-week'),('/intelligence?job='+chat_id,'assistant-action')]:
            page.goto(base+route)
            for width in [1365,390]:
                page.set_viewport_size({'width':width,'height':900});page.screenshot(path=str(OUT/f'{label}-{width}.png'),full_page=True);check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),f'{label} fits {width}')
        check(not errors,'No browser script errors');browser.close()
    (OUT/'results.json').write_text(json.dumps({'checks':checks,'errors':errors},indent=2),encoding='utf-8');print(f'{len(checks)} role/AI UI checks passed. Evidence: {OUT}')
finally:server.terminate();server.wait(timeout=10);log.close()

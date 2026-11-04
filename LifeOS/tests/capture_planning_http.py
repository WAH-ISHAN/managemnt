"""Disposable HTTP coverage for capture, scheduling, archive and locale boundaries."""
import http.cookiejar,json,os,re,shutil,socket,sqlite3,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
checks=0
def check(ok,label):
 global checks
 if not ok: raise AssertionError(label)
 checks+=1
with tempfile.TemporaryDirectory(prefix='lifeos-planning-http-') as tmp:
 data=Path(tmp)/'data';project=Path(tmp)/'app';data.mkdir()
 for folder in ['app','public']:shutil.copytree(ROOT/folder,project/folder)
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 browser=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def request(path,fields=None):
  body=urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None
  try:
   with browser.open(urllib.request.Request(f'http://127.0.0.1:{port}'+path,body),timeout=20) as response:return response.status,response.read(),response.url
  except urllib.error.HTTPError as e:return e.code,e.read(),e.url
 def read(sql,args=()):
  db=sqlite3.connect(data/'lifeos.db')
  try:return db.execute(sql,args).fetchall()
  finally:db.close()
 with (Path(tmp)/'server.log').open('w') as log:
  server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=project,env=dict(os.environ,LIFEOS_DATA_DIR=str(data)),stdout=log,stderr=log)
  try:
   for _ in range(100):
    try:
     if request('/health')[0]==200:break
    except OSError:pass
    time.sleep(.1)
   code,page,_=request('/planner/capture');check(code==200,'Capture available globally')
   token=re.search(rb'name="_csrf" value="([a-f0-9]+)"',page).group(1).decode()
   check(request('/planner/capture',{'text':'Unsafe'})[0]==419,'Capture CSRF')
   code,page,_=request('/planner/capture',{'_csrf':token,'text':'Call <script>alert(1)</script> tomorrow 5pm !high ~45m'})
   check(code==200 and b'&lt;script&gt;' in page and b'<script>alert(1)</script>' not in page,'Raw text escaped')
   check(read('SELECT count(*) FROM tasks')==[(0,)],'Capture preview has no task writes')
   identity=read('SELECT id FROM capture_inbox')[0][0]
   fields={'_csrf':token,'title':'Today','due':'2099-01-05','priority':'high','estimate_minutes':'45'}
   check(request(f'/planner/capture/{identity}/convert',fields)[0]==200,'Reviewed capture creates task')
   check(request(f'/planner/capture/{identity}/convert',fields)[0]==422,'Repeated conversion rejected')
   check(read('SELECT title,priority,estimate_minutes FROM tasks')==[('Today','high',45)],'Persisted edited capture')
   check(request('/planner/planning/preferences',{'_csrf':token,'start':'09:00','end':'17:00','days[]':[1,2,3,4,5,6,7],'archive_days':0})[0]==200,'Availability configured')
   code,page,_=request('/planner/planning/preview',{'_csrf':token,'date':'2099-01-05'})
   check(code==200 and b'Apply this plan' in page,'Plan preview shown')
   check(read('SELECT count(*) FROM planner_blocks')==[(0,)],'Preview no blocks')
   check(request('/planner/planning/apply',{'_csrf':token})[0]==200,'Apply saved preview')
   check(read('SELECT count(*) FROM planner_blocks')==[(1,)],'One time block created')
   check(request('/planner/planning/apply',{'_csrf':token})[0]==422,'Apply requires fresh preview')
   batch=read('SELECT id FROM planner_batches')[0][0]
   check(request(f'/planner/planning/{batch}/undo',{'_csrf':token})[0]==200,'Undo through HTTP')
   check(read('SELECT count(*) FROM planner_blocks')==[(0,)],'Undo removed generated block')
   check(request('/insights/trends')[0]==200,'Trends renders')
   check(request('/insights/trends/preferences',{'_csrf':token,'daily_minutes':0,'open_tasks':2})[0]==422,'Workload invalid capacity rejected')
   check(request('/settings/workspace/language',{'_csrf':token,'locale':'si'})[0]==200,'Sinhala chosen')
   code,page,_=request('/planner?view=tasks')
   check(code==200 and 'කාර්යයන්'.encode() in page,'Planner static text translated')
   check(b'<strong>Today</strong>' in page,'User task title never translated')
   check(request('/settings/workspace/language',{'_csrf':token,'locale':'ta'})[0]==200,'Tamil chosen')
   check('பணிகள்'.encode() in request('/planner?view=tasks')[1],'Tamil planner')
   check(request('/salary/alert-import')[0]==404,'Removed bank route absent')
   print(f'{checks} capture/planning HTTP checks passed.')
  finally:server.terminate();server.wait(timeout=10)

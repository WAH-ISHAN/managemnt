"""Desktop panel schedule, customization, quick add and note flows."""
import datetime, http.cookiejar, json, os, re, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path

root=Path(__file__).resolve().parents[1]
checks=0
def check(value,label):
    global checks
    assert value,label
    checks+=1

with tempfile.TemporaryDirectory(prefix='lifeos-panel-http-') as temporary:
    data=Path(temporary);project=data/'project'
    for folder in ['app','public']:shutil.copytree(root/folder,project/folder)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),urllib.request.ProxyHandler({}))
    def request(path,fields=None):
        data=urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None
        try:
            with opener.open(urllib.request.Request(base+path,data),timeout=15) as response:return response.status,response.read(),response.headers
        except urllib.error.HTTPError as error:return error.code,error.read(),error.headers
    with (data/'server.log').open('w') as log:
        server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=project,env=dict(os.environ,LIFEOS_DATA_DIR=temporary),stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    if request('/health')[0]==200:break
                except OSError:pass
                time.sleep(.1)
            request('/',None)
            page=request('/desktop/panel')[1]
            csrf=re.search(rb'<main data-csrf="([a-f0-9]+)"',page).group(1).decode()
            check(b'data-preferences-toggle' in page and b'data-add-form' in page,'Panel has customization and quick-add controls')
            check(b'data-cpu' not in page and b'data-gpu' not in page and b'data-memory' not in page,'Panel page contains no device-usage display')
            code,body,_=request('/desktop/panel/data');initial=json.loads(body)
            check(code==200 and all(key in initial for key in ['items','todos','notes','preferences']),'Schedule, to-do, note and preference data load')
            check(initial['preferences']['autoHide'] is True,'Auto-hide defaults on')
            check(request('/desktop/panel/add',{'title':'Blocked without token'})[0]==419,'Quick add requires CSRF')
            today=datetime.date.today().isoformat()
            check(request('/desktop/panel/add',{'_csrf':csrf,'kind':'task','title':'Panel task','due':today})[0]==200,'Task can be added')
            check(request('/desktop/panel/add',{'_csrf':csrf,'kind':'todo','title':'Panel to-do','due':''})[0]==200,'To-do can be added')
            check(request('/desktop/panel/add',{'_csrf':csrf,'kind':'note','title':'Panel note','body':'Remember this','due':''})[0]==200,'Note can be added')
            current=json.loads(request('/desktop/panel/data')[1])
            check(any(item['title']=='Panel task' for item in current['items']),'New task appears in today panel')
            check(any(item['title']=='Panel to-do' for item in current['todos']),'New to-do appears in quick list')
            note=next(item for item in current['notes'] if item['title']=='Panel note')
            check(note['body']=='Remember this','Note text is saved and returned')
            todo=current['todos'][0]['id']
            check(request(f'/desktop/panel/todo/{todo}/complete',{'_csrf':csrf})[0]==200,'To-do can be completed')
            check(all(item['id']!=todo for item in json.loads(request('/desktop/panel/data')[1])['todos']),'Completed to-do leaves the open list')
            check(request('/desktop/panel/preferences',{'_csrf':csrf,'showTasks':'0','showTodos':'1','showAssignments':'1','showExams':'1','showLectures':'1','showNotes':'1','autoHide':'0'})[0]==200,'Panel display choices save')
            updated=json.loads(request('/desktop/panel/data')[1])
            check(not any(item['kind']=='Task' for item in updated['items']),'Task category preference filters schedule')
            check(updated['preferences']['autoHide'] is False,'Auto-hide preference persists')
            check(request('/desktop/panel/preferences',{'_csrf':csrf,'showTasks':'1','textSize':'18','theme':'dark'})[0]==200,'Appearance and large text save')
            appearance=json.loads(request('/desktop/panel/data')[1])['preferences']
            check(appearance['textSize']==18 and appearance['theme']=='dark','Appearance survives subsequent requests')
            request('/desktop/panel/preferences',{'_csrf':csrf,'showNotes':'1','textSize':'999','theme':'untrusted'})
            validated=json.loads(request('/desktop/panel/data')[1])['preferences']
            check(validated['textSize']==18 and validated['theme']=='system','Unsupported appearance values are bounded')
            check(b'data-panel-width' in page and b'data-resize' in page and b'data-drag-handle' in page,'Panel exposes sizing and drag controls')
            check(request(f'/desktop/panel/note/{note["id"]}/delete',{'_csrf':csrf})[0]==200,'Note can be deleted')
            check(all(item['id']!=note['id'] for item in json.loads(request('/desktop/panel/data')[1])['notes']),'Deleted note leaves the panel')
            request('/desktop/panel/add',{'_csrf':csrf,'kind':'note','title':'Keep after restart','body':'Stored locally'})
            server.terminate();server.wait(timeout=10)
            server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=project,env=dict(os.environ,LIFEOS_DATA_DIR=temporary),stdout=log,stderr=log)
            for _ in range(100):
                try:
                    if request('/health')[0]==200:break
                except OSError:pass
                time.sleep(.1)
            restarted=json.loads(request('/desktop/panel/data')[1])
            check(restarted['preferences']['textSize']==18,'Panel appearance persists after server restart')
            check(any(n['title']=='Keep after restart' for n in restarted['notes']),'Panel notes persist after server restart')
            print(f'{checks} desktop panel HTTP checks passed.')
        finally:
            server.terminate();server.wait(timeout=10)

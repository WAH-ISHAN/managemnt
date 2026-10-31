"""Work management end-to-end: HTTP forms, boards, planning, files and exports."""
import base64, csv, http.cookiejar, io, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path
root=Path(__file__).resolve().parents[1]
checks=0
def check(condition,label):
    global checks
    assert condition,label
    checks+=1
with tempfile.TemporaryDirectory(prefix='lifeos-work-http-') as temporary:
    data=Path(temporary);project=data/'project'
    for folder in ['app','public']:shutil.copytree(root/folder,project/folder)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),urllib.request.ProxyHandler({}))
    def request(path,fields=None,raw=None,headers=None):
        body=raw if raw is not None else urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None
        try:
            with opener.open(urllib.request.Request(base+path,body,headers or {}),timeout=15) as response:return response.status,response.read(),response.headers,response.url
        except urllib.error.HTTPError as error:return error.code,error.read(),error.headers,error.url
    db=None
    with (data/'server.log').open('w') as log:
        server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=project,env=dict(os.environ,LIFEOS_DATA_DIR=temporary),stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    if request('/health')[0]==200:break
                except OSError:pass
                time.sleep(.1)
            page=request('/')[1];csrf=re.search(rb'name="_csrf" value="([a-f0-9]+)"',page).group(1).decode()
            request('/welcome',{'_csrf':csrf,'name':'Work HTTP owner','institution':'','areas[]':'study'})
            code,page,_,_=request('/work');check(code==200 and b'Create a workspace' in page and b'Work management' in page,'New Work module is available in LifeOS with real empty state')
            check(request('/work/workspace',{'name':'Missing CSRF'})[0]==419,'Workspace creation requires CSRF')
            for name in ['Personal workspace','Other workspace']:check(request('/work/workspace',{'_csrf':csrf,'name':name})[0]==200,'Workspace creation succeeds')
            db=sqlite3.connect(data/'lifeos.db');db.row_factory=sqlite3.Row
            workspace,otherWorkspace=[row[0] for row in db.execute('SELECT id FROM work_workspaces ORDER BY id')]
            for w,identifier in [(workspace,'LIFE'),(otherWorkspace,'OTHER')]:check(request(f'/work/workspace/{w}/projects',{'_csrf':csrf,'name':'Project '+identifier,'identifier':identifier,'kind':'study'})[0]==200,'Project creation provisions its workflow')
            p,otherProject=[row[0] for row in db.execute('SELECT id FROM work_projects ORDER BY id')]
            check(request(f'/work/workspace/{workspace}/projects',{'_csrf':csrf,'name':'Duplicate','identifier':'LIFE'})[0]==422,'Duplicate project identifiers rejected through HTTP')
            person=db.execute('SELECT id FROM work_people WHERE workspace_id=? AND is_self=1',(workspace,)).fetchone()[0]
            check(request(f'/work/workspace/{workspace}/people',{'_csrf':csrf,'name':'Local assignee','email':'assignee@example.com'})[0]==200,'Assignee record created without sending an invitation')
            for tab in ['overview','projects','mine','intake','people','settings','archived']:check(request(f'/work?workspace={workspace}&tab={tab}')[0]==200,'Workspace tab renders: '+tab)
            for tab in ['issues','cycles','modules','releases','pages','views','analytics','activity','settings']:check(request(f'/work/project/{p}?tab={tab}')[0]==200,'Project option renders: '+tab)
            check(request(f'/work/project/{p}?tab=unknown')[0]==422,'Unknown project option rejected')
            check(request(f'/work/project/{p}/label',{'_csrf':csrf,'name':'Feature','color':'#3688ff'})[0]==200,'Project label creation works')
            label=db.execute('SELECT id FROM work_labels WHERE project_id=?',(p,)).fetchone()[0]
            for name in ['First cycle','Next cycle']:check(request(f'/work/project/{p}/cycle',{'_csrf':csrf,'name':name,'starts_on':'2026-10-01','ends_on':'2026-10-31','status':'planned'})[0]==200,'Cycle creation works')
            cycles=[row[0] for row in db.execute('SELECT id FROM work_cycles WHERE project_id=? ORDER BY id',(p,))]
            check(request(f'/work/project/{p}/module',{'_csrf':csrf,'name':'Research module','status':'planned'})[0]==200,'Module creation works')
            module=db.execute('SELECT id FROM work_modules WHERE project_id=?',(p,)).fetchone()[0]
            fields={'_csrf':csrf,'title':'First work item','description':'## Description\n**Real content** <script>alert(1)</script>','priority':'2','due_date':'2026-10-29','start_date':'2026-10-01','estimate':'3','assignee_ids[]':[person],'label_ids[]':[label],'cycle_id':cycles[0],'module_id':module}
            code,page,_,url=request(f'/work/project/{p}/issues',fields);check(code==200 and b'LIFE-1' in page,'Issue creation opens a real work item with project sequence ID');issue=int(url.rsplit('/',1)[1])
            check(b'<strong>Real content</strong>' in page and b'<script>alert(1)</script>' not in page,'Markdown formatting is rendered without authored script execution')
            check(len(db.execute('SELECT * FROM work_issue_assignees WHERE issue_id=?',(issue,)).fetchall())==1,'Assignee persisted through HTTP')
            for layout in ['list','board','calendar','spreadsheet','gantt']:
                code,page,_,_=request(f'/work/project/{p}?layout={layout}&month=2026-10')
                check(code==200 and b'First work item' in page,'Real item appears in '+layout+' layout')
            check(request(f'/work/project/{p}?layout=invented')[0]==422,'Unsupported layout rejected')
            states={row['state_group']:row['id'] for row in db.execute('SELECT * FROM work_states WHERE project_id=?',(p,))}
            code,body,_,_=request(f'/work/project/{p}/issues/{issue}/move',{'_csrf':csrf,'state_id':states['started'],'version':1},headers={'X-Requested-With':'LifeOS-Work-Board'})
            check(code==200 and json.loads(body)['ok'],'Kanban move endpoint confirms saved state')
            check(db.execute('SELECT state_id,version FROM work_issues WHERE id=?',(issue,)).fetchone()[:]==(states['started'],2),'Board movement updates SQLite and version')
            check(request(f'/work/project/{p}/issues/{issue}/move',{'_csrf':csrf,'state_id':states['completed'],'version':1})[0]==422,'Stale board card rejected')
            check(request(f'/work/project/{otherProject}/issues/{issue}/move',{'_csrf':csrf,'state_id':states['completed'],'version':2})[0]==422,'Cross-project board move rejected')
            check(request(f'/work/project/{p}/issues/{issue}/field',{'_csrf':csrf,'field':'priority','value':'1','version':2})[0]==200,'Spreadsheet cell update works')
            check(request(f'/work/project/{p}/views',{'_csrf':csrf,'name':'My urgent board','layout':'board','filters[assignee]':person,'filters[priority]':'1'})[0]==200,'Saved view persists and opens')
            view=db.execute('SELECT id FROM work_views WHERE project_id=?',(p,)).fetchone()[0]
            check(b'First work item' in request(f'/work/project/{p}?view_id={view}')[1],'Saved view filters resolve actual items')
            check(request(f'/work/issue/{issue}/comments',{'_csrf':csrf,'body':'A real comment'})[0]==200,'Comments save and render')
            comment=db.execute('SELECT id FROM work_comments WHERE issue_id=?',(issue,)).fetchone()[0]
            check(request(f'/work/issue/{issue}/comments',{'_csrf':csrf,'comment_id':comment,'version':1,'body':'Edited comment'})[0]==200,'Comment edits use versions')
            check(request(f'/work/issue/{issue}/comments',{'_csrf':csrf,'comment_id':comment,'version':1,'body':'Stale'})[0]==422,'Stale comment rejected')
            childResponse=request(f'/work/project/{p}/issues',{'_csrf':csrf,'title':'Child item','parent_id':issue});child=int(childResponse[3].rsplit('/',1)[1]);check(childResponse[0]==200,'Sub-issue creation works')
            check(request(f'/work/project/{p}/issues/{issue}/save',{'_csrf':csrf,'version':3,'parent_id':child})[0]==422,'Parent cycle rejected through HTTP')
            check(request(f'/work/issue/{issue}/relations',{'_csrf':csrf,'kind':'blocks','related_id':child})[0]==200,'Blocking relationship creation works')
            check(request(f'/work/issue/{child}/relations',{'_csrf':csrf,'kind':'blocks','related_id':issue})[0]==422,'Blocking graph cannot cycle')
            check(request(f'/work/issue/{issue}/time',{'_csrf':csrf,'minutes':'25','on_date':'2026-10-29'})[0]==200,'Manual time entry works')
            check(request(f'/work/issue/{issue}/timer',{'_csrf':csrf,'action':'start'})[0]==200,'Timer starts')
            check(request(f'/work/issue/{child}/timer',{'_csrf':csrf,'action':'start'})[0]==422,'Second workspace timer blocked')
            check(request(f'/work/issue/{issue}/timer',{'_csrf':csrf,'action':'stop','minutes':'10'})[0]==200,'Timer stops with corrected actual minutes')
            check(db.execute('SELECT SUM(minutes) FROM work_time_entries').fetchone()[0]==35,'Time records total correctly')
            png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS8AAAAASUVORK5CYII=');boundary='work-'+os.urandom(12).hex()
            raw=f'--{boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="work-reference.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+f'\r\n--{boundary}--\r\n'.encode()
            check(request(f'/work/issue/{issue}/attachments',raw=raw,headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})[0]==200,'Real file attachment upload succeeds')
            document=db.execute('SELECT document_id FROM work_attachments WHERE issue_id=?',(issue,)).fetchone()[0]
            check(request(f'/documents/{document}/download')[1]==png,'Attachment downloads original exact bytes')
            check(request(f'/work/project/{p}/pages',{'_csrf':csrf,'title':'Project specification','body':'## Original content','pinned':'1'})[0]==200,'Project page creation works')
            pageId=db.execute('SELECT id FROM work_pages WHERE project_id=?',(p,)).fetchone()[0]
            check(request(f'/work/project/{p}/pages',{'_csrf':csrf,'page_id':pageId,'title':'Updated specification','body':'Updated content','version':1})[0]==200,'Project page edits persist history')
            check(request(f'/work/project/{p}/page/{pageId}/rollback',{'_csrf':csrf,'version':2,'target':1})[0]==200,'Page rollback works')
            check(db.execute('SELECT body,version FROM work_pages WHERE id=?',(pageId,)).fetchone()[:]==('## Original content',3),'Page rollback creates a new version with earlier content')
            check(request(f'/work/project/{p}/page/{pageId}/issue',{'_csrf':csrf,'title':'Converted task','description':'From the specification'})[0]==200,'Page to linked work-item conversion works')
            check(request(f'/work/workspace/{workspace}/favorite',{'_csrf':csrf,'entity':'issue','entity_id':issue,'on':'1'})[0]==200,'Favourite saves')
            check(b'First work item' in request(f'/work?workspace={workspace}')[1],'Workspace dashboard includes real favourite and assigned work')
            code,body,_,_=request(f'/work/project/{p}/export');export=json.loads(body);check(code==200 and export['format']=='lifeos-work-v1' and len(export['data']['work_issues'])==3,'Project export includes actual issues and metadata')
            code,body,headers,_=request(f'/work/project/{p}/export.csv');rows=list(csv.reader(io.StringIO(body.decode())));check(code==200 and len(rows)==4 and rows[1][0].startswith('LIFE-'),'CSV export contains real work items')
            version=db.execute('SELECT version FROM work_issues WHERE id=?',(issue,)).fetchone()[0]
            check(request(f'/work/project/{p}/issues/{issue}/lifecycle',{'_csrf':csrf,'action':'trash','version':version})[0]==200,'Trash action preserves issue detail')
            check(b'First work item' in request(f'/work/project/{p}?scope=trash')[1],'Trash view shows removed issue')
            check(request(f'/work/project/{p}/issues/{issue}/lifecycle',{'_csrf':csrf,'action':'restore','version':version+1})[0]==200,'Trashed item restores')
            check(request(f'/work/project/{p}/release',{'name':'No token'})[0]==419,'Release changes require CSRF')
            check(request(f'/work/project/{p}/release',{'_csrf':csrf,'name':'Version 1','starts_on':'2026-10-01','release_on':'2026-11-01','status':'planned'})[0]==200,'Project release can be created through HTTP')
            release=db.execute('SELECT id FROM work_releases WHERE project_id=?',(p,)).fetchone()[0]
            check(b'Version 1' in request(f'/work/project/{p}?tab=releases')[1],'Release management page renders stored versions')
            check(request(f'/work/project/{p}/release',{'_csrf':csrf,'release_id':release,'name':'Version 1','status':'released','version':1})[0]==200,'Release status can be changed with its version')
            check(request(f'/work/project/{p}/release',{'_csrf':csrf,'release_id':release,'name':'Stale version','version':1})[0]==422,'Stale release edits rejected through HTTP')
            check(request(f'/work/project/{otherProject}/issues',{'_csrf':csrf,'title':'Wrong release','fix_version_id':release})[0]==422,'Cross-project fix-version assignment rejected')
            code,typedPage,_,typedUrl=request(f'/work/project/{p}/issues',{'_csrf':csrf,'title':'HTTP typed bug','issue_type':'bug','fix_version_id':release,'state_id':states['started']});typed=int(typedUrl.rsplit('/',1)[1]);check(code==200 and b'Version 1' in typedPage and b'Bug' in typedPage,'Issue form persists and displays real type and fix version')
            check(request(f'/work/project/{p}/issues',{'_csrf':csrf,'title':'Orphan subtask','issue_type':'subtask'})[0]==422,'Typed subtask must select a parent')
            check(b'HTTP typed bug' in request(f'/work/project/{p}?issue_type=bug&release={release}')[1],'Type and release filters find their actual issue')
            check(request(f'/work/project/{p}/issues/{typed}/field',{'_csrf':csrf,'field':'issue_type','value':'story','version':1})[0]==200,'Spreadsheet type cell saves optimistically')
            check(db.execute('SELECT issue_type,fix_version_id FROM work_issues WHERE id=?',(typed,)).fetchone()[:]==('story',release),'Spreadsheet type edit preserves release metadata')
            check(request(f'/work/project/{p}/views',{'_csrf':csrf,'name':'Release stories','layout':'list','filters[issue_type]':'story','filters[release]':release})[0]==200,'Type and release can be saved in a view')
            savedView=json.loads(db.execute('SELECT filters FROM work_views WHERE project_id=? AND name=?',(p,'Release stories')).fetchone()[0]);check(savedView['issue_type']=='story' and savedView['release']==release,'Saved type and release filter values persist')
            stateRow=db.execute('SELECT * FROM work_states WHERE id=?',(states['started'],)).fetchone()
            check(request(f'/work/project/{p}/state',{'_csrf':csrf,'item_id':stateRow['id'],'name':stateRow['name'],'state_group':stateRow['state_group'],'color':stateRow['color'],'position':stateRow['position'],'wip_limit':'1'})[0]==200,'Workflow settings save a WIP limit')
            check(request(f'/work/project/{p}/issues',{'_csrf':csrf,'title':'Second WIP item','state_id':states['started']})[0]==200,'WIP warning does not block creating or moving work')
            board=request(f'/work/project/{p}?layout=board&issue_type=story')[1]
            check(b'work-wip-warning' in board and b'Over limit' in board and b'data-work-wip-limit="1"' in board,'Filtered board warns using full-project WIP count')
            check(b'Fix version' in request(f'/work/project/{p}?layout=spreadsheet')[1],'Spreadsheet exposes fix-version cells')
            latestExport=json.loads(request(f'/work/project/{p}/export')[1]);check(len(latestExport['data']['work_releases'])==1,'JSON export includes releases')
            csvRows=list(csv.reader(io.StringIO(request(f'/work/project/{p}/export.csv')[1].decode())));check(csvRows[0][-2:]==['Type','Fix version'],'CSV export includes type and fix version columns')
            check(request(f'/work/project/{p}/archive',{'_csrf':csrf})[0]==200,'Project archive action works')
            check(request(f'/work/project/{p}/issues',{'_csrf':csrf,'title':'Not allowed'})[0]==422,'Archived project rejects new issues')
            check(request(f'/work/project/{p}/archive',{'_csrf':csrf,'restore':'1'})[0]==200,'Project restore works')
            check(request(f'/work/project/{p}?tab=analytics')[0]==200,'Analytics renders real populated project')
            check(db.execute('PRAGMA integrity_check').fetchone()[0]=='ok' and db.execute('PRAGMA foreign_key_check').fetchone() is None,'All database references remain consistent')
            print(f'{checks} Work management HTTP end-to-end checks passed.')
        finally:
            if db:db.close()
            server.terminate();server.wait(timeout=15)

"""HTTP acceptance checks against an isolated, temporary SQLite database."""
import http.cookiejar
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import re
import socket
import shutil
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parents[1]
checks = 0
# Match PHP's explicit Asia/Colombo timezone, including on UTC Windows runners.
# A fixed offset needs no optional Windows tzdata package; Colombo has no DST.
APP_TIMEZONE = timezone(timedelta(hours=5, minutes=30))
def check(ok, label):
    global checks
    assert ok, label
    checks += 1

with tempfile.TemporaryDirectory(prefix="lifeos-http-") as data:
    project = Path(data)/'project'
    for folder in ['app','public']: shutil.copytree(root/folder,project/folder)
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, LIFEOS_DATA_DIR=data)
    with open(Path(data)/"server.log", "w") as log:
        server = subprocess.Popen([os.environ.get("LIFEOS_QA_PHP", "php"), "-S", f"127.0.0.1:{port}", "-t", "public", "public/index.php"], cwd=project, env=env, stdout=log, stderr=log)
        base = f"http://127.0.0.1:{port}"
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), urllib.request.ProxyHandler({}))
        def request(path, fields=None, headers=None, raw=None):
            body = raw if raw is not None else (urllib.parse.urlencode(fields, doseq=True).encode() if fields is not None else None)
            req = urllib.request.Request(base+path, body, headers or {})
            try:
                with opener.open(req, timeout=10) as response: return response.status, response.read(), response.headers, response.url
            except urllib.error.HTTPError as error: return error.code, error.read(), error.headers, error.url
        def token(body):
            return re.search(rb'name="_csrf" value="([a-f0-9]+)"', body).group(1).decode()
        def multipart(path, fields, file_field, filename, content):
            boundary = 'lifeos-' + os.urandom(12).hex()
            chunks = []
            for name, value in fields.items():
                chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
            chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + b'\r\n')
            chunks.append(f'--{boundary}--\r\n'.encode())
            return request(path, headers={'Content-Type':f'multipart/form-data; boundary={boundary}'}, raw=b''.join(chunks))
        try:
            for _ in range(100):
                try:
                    if request("/health")[0] == 200: break
                except OSError: pass
                time.sleep(.1)
            code, page, headers, _ = request("/")
            check(code == 200 and b"Create my workspace" in page, "First boot opens onboarding")
            check(b'/assets/brand/lifeos-mark.png' in page and b'/assets/brand/favicon-32.png' in page, 'Onboarding uses the new LifeOS brand')
            for asset in ['/assets/brand/lifeos-mark.png','/assets/brand/favicon-32.png']:
                asset_code, asset_body, asset_headers, _ = request(asset)
                check(asset_code == 200 and asset_body.startswith(b'\x89PNG\r\n\x1a\n') and 'image/png' in asset_headers.get('Content-Type',''), 'Brand image served correctly: ' + asset)
            check(headers.get('Referrer-Policy') == 'same-origin', 'Same-origin form POSTs preserve the browser Origin header')
            csrf = token(page)
            check(request('/welcome', {'_csrf':csrf,'name':'Acceptance student','institution':'Test university','areas[]':['study','money']})[0] == 200, 'Onboarding creates a personal workspace')
            website_ids = []
            for index in range(10):
                site_code, site_page, _, site_url = request('/websites/profile', {'_csrf':csrf,'name':f'Owner website {index}', 'business_name':f'Business {index}', 'base_url':f'https://site{index}.example.com'})
                check(site_code == 200 and b'Sign in to this website' in site_page, 'Saved website opens its client login panel')
                website_ids.append(int(site_url.rsplit('/', 1)[1]))
            code, websites_page, _, _ = request('/websites')
            check(code == 200 and all(f'Owner website {index}'.encode() in websites_page for index in range(10)), 'One website dashboard lists all ten client websites')
            check(b'Sign in with your website provider' in websites_page and b'action="/websites/gateway/login"' in websites_page, 'Provider gateway login exposes client credentials only')
            check(request('/websites/gateway/login', {'username':'client','password':'private','gateway_url':'https://gateway.example.com/index.php'})[0] == 419, 'Gateway login requires local CSRF protection')
            check(request('/websites/gateway/login', {'_csrf':csrf,'username':'client','password':'private','gateway_url':'http://127.0.0.1/private'})[0] == 422, 'Gateway login rejects insecure local endpoints')
            _, site_page, _, _ = request(f'/websites/{website_ids[0]}')
            check(all(f'Owner website {index}'.encode() in site_page for index in range(10)), 'Website switcher contains all ten profiles')
            check(b'autocomplete="username"' in site_page and b'autocomplete="current-password"' in site_page, 'Client login renders username and password fields')
            check(b'Advanced: connect with an API token' in site_page, 'Existing token integrations remain available')
            check(request(f'/websites/{website_ids[0]}/login', {'username':'client','password':'private'})[0] == 419, 'Website login requires local CSRF protection')
            check(request(f'/websites/{website_ids[0]}/login', {'_csrf':csrf,'username':'client','password[]':'private'})[0] == 422, 'Website login rejects malformed credentials')
            check(request('/websites/setup')[0] == 200, 'Updated multi-website setup guide renders')
            check(b'data-website-business' in websites_page and all(f'Business {index}'.encode() in websites_page for index in range(10)), 'Owner dashboard exposes all ten business groups')
            check(b'name="business_name"' in site_page, 'Website details support assigning a business')
            with sqlite3.connect(Path(data)/'lifeos.db') as website_db:
                website_db.execute('INSERT INTO website_operations(website_id,action,outcome) VALUES (?,?,?)', (website_ids[0], 'connect', 'failed'))
                website_db.execute('INSERT INTO website_operations(website_id,action,outcome) VALUES (?,?,?)', (website_ids[1], 'refresh', 'succeeded'))
            website_db.close()
            activity_page = request('/websites')[1]
            check(b'Across your websites' in activity_page and b'Owner website 0' in activity_page and b'Owner website 1' in activity_page, 'Owner can review actual activity across business websites')

            code, page, _, _ = request('/')
            check(code == 200 and b"Your day, in perspective." in page and b'No data yet' in page, "Empty dashboard after onboarding")
            check(b'/assets/comfort.css' in page and b'data-quick-add' in page and b'More tools' in page and b'Your daily progress' in page, 'Task-first dashboard and quick action navigation available')
            check(b'/assets/brand/lifeos-mark.png' in page and b'/assets/motion.css' in page and request('/assets/motion.css')[0] == 200, 'Branded application shell loads motion styles')
            check(request('/?date=2026-02-30')[0] == 422 and request('/?date='+(datetime.now(APP_TIMEZONE)+timedelta(days=1)).strftime('%Y-%m-%d'))[0] == 422, 'Daily review rejects invalid and invented future activity dates')
            check(b'placeholder="e.g. CreativeFX"' not in request('/investments')[1], 'Example asset name removed from user-facing forms')
            check(request("/salary")[0] == 200 and request("/settings")[0] == 200, "Money and settings pages")
            csrf = token(request("/salary")[1])
            check(request("/salary", {"amount":"20"})[0] == 419, "Missing CSRF rejected")
            check(request("/salary", {"_csrf":csrf}, {"Origin":"https://example.org"})[0] == 403, "Foreign origin rejected")
            check(request("/salary", {"_csrf":csrf}, {"Origin":"null"})[0] == 403, "Opaque origins remain rejected")
            check(request("/salary", {"_csrf":csrf,"date":"2026-10-24","source":"<script>alert(1)</script>","amount":"100.01","type":"salary"})[0] == 200, "Income creates and redirects")
            code, page, _, _ = request("/salary?month=2026-10")
            check(b"&lt;script&gt;" in page and b"<script>alert(1)" not in page, "Stored source escaped")
            db = sqlite3.connect(Path(data)/"lifeos.db")
            check(round(db.execute("select sum(amount) from allocations").fetchone()[0]*100) == 10001, "HTTP income allocation total")
            cost_date=datetime.now(APP_TIMEZONE).strftime('%Y-%m-%d')
            code, cost_page, _, _ = request(f'/websites/{website_ids[0]}/costs', {'_csrf':csrf,'date':cost_date,'kind':'hosting','amount':'250.50','vendor':'QA hosting','notes':'<script>alert(1)</script>'})
            check(code == 200 and b'QA hosting' in cost_page and b'Website-only cost' in cost_page, 'Website costs can be added without a website API login')
            check(b'&lt;script&gt;alert(1)&lt;/script&gt;' in cost_page and b'<script>alert(1)</script>' not in cost_page, 'Website cost notes are escaped')
            check(b'All website costs' in request('/websites')[1], 'Website dashboard summarizes maintenance costs')
            cost_category=db.execute("SELECT id FROM expense_categories WHERE name='Bills'").fetchone()[0]
            cost_balance=db.execute('SELECT balance FROM accounts WHERE id=1').fetchone()[0]
            check(request(f'/websites/{website_ids[0]}/costs', {'_csrf':csrf,'date':cost_date,'kind':'maintenance','amount':'25.75','vendor':'Linked QA maintenance','account_id':'1','category_id':cost_category})[0] == 200, 'Website costs can record one linked Money expense')
            linked_cost, linked_expense=db.execute('SELECT id,expense_id FROM website_costs WHERE vendor=?', ('Linked QA maintenance',)).fetchone()
            check(round(db.execute('SELECT balance FROM accounts WHERE id=1').fetchone()[0]*100) == round(cost_balance*100)-2575, 'Linked website cost debits the chosen account exactly once')
            check(request(f'/websites/{website_ids[0]}/costs/{linked_cost}/delete', {'_csrf':csrf})[0] == 200, 'Linked website cost can be removed')
            check(db.execute('SELECT id FROM expenses WHERE id=?', (linked_expense,)).fetchone() is None and db.execute('SELECT balance FROM accounts WHERE id=1').fetchone()[0] == cost_balance, 'Removing the cost reverses the linked Money expense')
            check(request(f'/websites/{website_ids[0]}/remove', {'_csrf':csrf})[0] == 422, 'Website profiles with cost history cannot be silently removed')
            check(request(f'/websites/{website_ids[0]}?cost_month=all')[0] == 200, 'All-date maintenance history renders')
            check(request("/expenses", {"_csrf":csrf,"date":"2026-10-24","category":"1","account":"1","amount":"2.50","note":"Lunch"})[0] == 200, "Expense creates")
            check(request("/salary", {"_csrf":csrf,"date":"2026-02-30","source":"Bad","amount":"1","type":"salary"})[0] == 422, "Invalid request rejected")
            _, fragment, _, _ = request("/salary", headers={"HX-Request":"true"})
            check(b'<main id="main"' in fragment and b"<!doctype" not in fragment, "HTMX main fragment")
            check(request("/does-not-exist")[0] == 404, "404 response")
            check(request("/app/Core/Db.php")[0] == 404 and request("/data/lifeos.db")[0] == 404, "Private files not served")
            check(request('/investments')[0] == 200 and request('/uni')[0] == 200 and request('/lessons')[0] == 200 and request('/review')[0] == 200, 'All module pages load')
            check(request('/investments', {'_csrf':csrf,'name':'CreativeFX','type':'business','start_date':'2026-01-01','notes':'Test business'})[0] == 200, 'Create business asset')
            asset = db.execute('select id from assets').fetchone()[0]
            check(request(f'/investments/{asset}/txn', {'_csrf':csrf,'date':'2026-01-01','type':'deposit','amount':'50000','note':'Capital'})[0] == 200, 'Deposit into asset')
            check(request(f'/investments/{asset}/txn', {'_csrf':csrf,'date':'2026-01-02','type':'valuation','amount':'65000','note':'Value'})[0] == 200, 'Add valuation')
            check(b'30.0%' in request(f'/investments/{asset}')[1], 'ROI renders in asset page')
            check(request(f'/investments/{asset}/metric', {'_csrf':csrf,'month':'2026-10','revenue':'30000','cost':'10000'})[0] == 200, 'Monthly business metrics')
            check(request('/goals', {'_csrf':csrf,'title':'New laptop','target':'100000','asset_id':asset,'due':'2026-12-31'})[0] == 200, 'Linked goal')
            check(request('/uni/module', {'_csrf':csrf,'name':'Algorithms','code':'CS1','credits':'4','semester':'1'})[0] == 200, 'Create first module')
            request('/uni/module', {'_csrf':csrf,'name':'Math','code':'CS2','credits':'2','semester':'1'})
            module_ids = [r[0] for r in db.execute('select id from uni_modules order by id')]
            for module_id, grade in zip(module_ids, ['A','B']): request(f'/uni/module/{module_id}/grade', {'_csrf':csrf,'grade':grade})
            check(b'3.67' in request('/uni?view=modules')[1], 'Weighted GPA renders')
            university_home = request('/uni')[1]
            check(all(f'/uni/add/{kind}'.encode() in university_home for kind in ['module','assignment','exam','class']), 'University overview exposes four independent add actions')
            exam_form = request('/uni/add/exam')[1]
            check(b'action="/uni/exam"' in exam_form and b'name="new_module_name"' in exam_form and b'action="/uni/assignment"' not in exam_form, 'Exam entry has its own form without assignment fields')
            check(request('/uni/add/unknown')[0] == 422, 'Unknown university entry type rejected')
            check(db.execute('SELECT COUNT(*) FROM assignments').fetchone()[0] == 0, 'Independence fixture starts with no assignments')
            check(request('/uni/exam', {'_csrf':csrf,'module_id':module_ids[0],'date':'2026-10-30','venue':'Independent first exam'})[0] == 200, 'Exam can be added before any assignment')
            before_modules = db.execute('SELECT COUNT(*) FROM uni_modules').fetchone()[0]
            check(request('/uni/exam', {'_csrf':csrf,'new_module_name':'Inline exam module','date':'2026-10-31','venue':'Independent module exam'})[0] == 200, 'Exam can create its module in the same action')
            check(db.execute("SELECT credits,grade FROM uni_modules WHERE name='Inline exam module'").fetchone() == (0, None), 'Quick-created modules start ungraded without changing GPA')
            check(b'3.67' in request('/uni?view=modules')[1], 'Inline module creation preserves credit-weighted GPA')
            inline_module = db.execute("SELECT id FROM uni_modules WHERE name='Inline exam module'").fetchone()[0]
            check(request(f'/uni/module/{inline_module}/edit')[0] == 200, 'Module details have an independent edit page')
            check(request(f'/uni/module/{inline_module}/edit', {'_csrf':csrf,'name':'Inline exam module','code':'INLINE','credits':'3','semester':'2'})[0] == 200, 'Quick-created module credits can be edited later')
            check(db.execute('SELECT credits FROM uni_modules WHERE id=?', (inline_module,)).fetchone()[0] == 3 and db.execute('SELECT COUNT(*) FROM exams WHERE module_id=?', (inline_module,)).fetchone()[0] == 1, 'Editing a module preserves its linked exam')
            check(request('/uni/exam', {'_csrf':csrf,'new_module_name':'Must roll back','date':'2026-02-30'})[0] == 422, 'Invalid exam is rejected')
            check(db.execute('SELECT COUNT(*) FROM uni_modules').fetchone()[0] == before_modules + 1, 'Failed exam does not leave an orphan module')
            check(request('/uni/exam', {'_csrf':csrf,'module_id':module_ids[0],'new_module_name':'Ambiguous','date':'2026-10-31'})[0] == 422, 'Ambiguous module choices are rejected')
            check(request('/uni/timetable', {'_csrf':csrf,'new_module_name':'Independent class module','day':'2','start_time':'10:00','end_time':'11:00','venue':'Independent class'})[0] == 200, 'A class and its module can be added without assignments')
            check(db.execute('SELECT COUNT(*) FROM assignments').fetchone()[0] == 0, 'Independent exams and classes create no assignment records')
            check(request('/uni/assignment', {'_csrf':csrf,'module_id':module_ids[0],'title':'Essay','due_date':'2026-01-01','weight':'20'})[0] == 200, 'Assignment entry')
            check(b'Overdue' in request('/uni')[1], 'Overdue assignment label')
            check(request('/uni/exam', {'_csrf':csrf,'module_id':module_ids[0],'date':'2026-10-30','venue':'Room A'})[0] == 200, 'Exam entry')
            check(request('/uni/timetable', {'_csrf':csrf,'module_id':module_ids[0],'day':'1','start_time':'09:00','end_time':'11:00','venue':'Room B'})[0] == 200, 'Timetable entry')
            check(request('/uni?view=timetable')[0] == 200 and b'Room B' in request('/uni?view=timetable')[1], 'Timetable renders')
            lesson_fields = {'_csrf':csrf,'area':'Uni','what_happened':'Missed planning','root_cause':'Late start','lesson':'Plan earlier','action':'Make a study plan','due':'2026-10-25'}
            check(request('/lessons',lesson_fields)[0] == 200, 'Failure creates lesson and task')
            request('/lessons',lesson_fields)
            task = db.execute('select task_id from lessons order by id limit 1').fetchone()[0]
            check(request(f'/tasks/{task}/done', {'_csrf':csrf,'return_to':'/review'})[0] == 200, 'Lesson task completion')
            check(db.execute('select status from lessons where task_id=?',(task,)).fetchone()[0] == 'applied', 'Lesson applied through HTTP')
            check(b'Recurring pattern' in request('/')[1], 'Dashboard repeated-failure warning')
            for score in ['5','9']: check(request('/review', {'_csrf':csrf,'wins':'Shipped','fails':'Late','score':score,'focus':'Start earlier'})[0] == 200, 'Save weekly review')
            check(db.execute('select count(*) from weekly_reviews').fetchone()[0] == 1, 'One review per week')
            check(request('/settings/export/income')[0] == 200 and b'source' in request('/settings/export/income')[1], 'CSV export')
            check(request('/settings/export/settings')[0] == 404, 'Settings secrets excluded from CSV')
            check(request('/settings/report')[0] == 200 and b'Print / Save as PDF' in request('/settings/report')[1], 'Printable PDF report')
            check(request('/assets/chart.min.js')[0] == 200, 'Chart library available offline')
            # Exercise every added module through its real HTTP forms and redirects.
            now = datetime.now(APP_TIMEZONE)
            today = now.strftime('%Y-%m-%d')
            month = now.strftime('%Y-%m')
            for path in ['/freedom','/planner','/planner?view=tasks','/planner?view=habits','/planner?view=routines','/planner?view=appointments','/growth','/career','/career?view=courses','/career?view=applications','/focus','/insights','/documents','/documents?trash=1','/settings/encrypted','/salary/plan','/salary/calendar','/salary/recurring']:
                code, body, _, _ = request(path)
                check(code == 200 and b'<h1>' in body and b'Warning:' not in body, 'Module page: ' + path)

            check(request('/freedom/settings', {'_csrf':csrf,'weekly_work_hours':'45'})[0] == 200, 'Save working-week context')
            check(request('/freedom/settings', {'_csrf':csrf,'weekly_work_hours':'169'})[0] == 422, 'Working week cannot exceed 168 hours')
            check(request('/freedom/time', {'_csrf':csrf,'date':today,'category':'rest','minutes':'30','note':'A quiet walk'})[0] == 200, 'Personal time log create')
            personal_time = db.execute("select id from freedom_time where note='A quiet walk'").fetchone()[0]
            check(b'A quiet walk' in request('/freedom')[1] and b'Edit your time' in request('/freedom?edit='+str(personal_time))[1], 'Personal time and edit form render')
            check(request(f'/freedom/time/{personal_time}/update', {'_csrf':csrf,'date':today,'category':'health','minutes':'45','note':'A longer walk'})[0] == 200 and db.execute('select minutes from freedom_time where id=?',(personal_time,)).fetchone()[0] == 45, 'Personal time edit')
            check(request('/freedom/time', {'_csrf':csrf,'date':(now+timedelta(days=1)).strftime('%Y-%m-%d'),'category':'rest','minutes':'30'})[0] == 422, 'Personal time excludes invented future activity')
            check(request('/freedom/time', {'_csrf':csrf,'date':today,'category':'rest','minutes':'1440'})[0] == 422, 'Personal time cannot exceed one day')
            check(request(f'/freedom/time/{personal_time}/delete', {'_csrf':csrf})[0] == 200 and db.execute('select id from freedom_time where id=?',(personal_time,)).fetchone() is None, 'Personal time delete')

            check(request('/planner/tasks', {'_csrf':csrf,'title':'HTTP planner task','due':today})[0] == 200, 'Planner task create')
            planner_task = db.execute("select id from tasks where title='HTTP planner task'").fetchone()[0]
            check(request(f'/planner/tasks/{planner_task}/edit', {'_csrf':csrf,'title':'Updated planner task','due':today})[0] == 200, 'Planner task edit')
            for state in ['done','open']:
                check(request(f'/planner/tasks/{planner_task}/status', {'_csrf':csrf,'status':state})[0] == 200 and db.execute('select status from tasks where id=?',(planner_task,)).fetchone()[0] == state, 'Planner task ' + state)
                check((db.execute('select completed_at from tasks where id=?',(planner_task,)).fetchone()[0] is not None) == (state == 'done'), 'Daily task completion date follows actual status ' + state)
            check(request('/planner/tasks', {'_csrf':csrf,'title':'Invalid','due':'2026-02-30'})[0] == 422, 'Planner rejects invalid dates')
            context = {'return_view':'tasks','return_date':today,'return_filter':'open','return_q':'Updated planner'}
            code, body, _, url = request(f'/planner/tasks/{planner_task}/edit', dict(context, _csrf=csrf, title='Updated planner task', due=today))
            query = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)
            check(code == 200 and query == {'view':['tasks'],'date':[today],'filter':['open'],'q':['Updated planner']}, 'Task edit preserves date and search after HTTP redirect')
            check(b'1 of 1 matching tasks' in body and b'aria-current="date"' in body, 'Filtered tasks and weekly date navigation render together')
            code, _, _, url = request(f'/planner/tasks/{planner_task}/status', {'_csrf':csrf,'status':'done','return_view':'today','return_date':today})
            check(code == 200 and urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['view'] == ['today'], 'Daily completion returns to daily view')
            check(request(f'/planner/tasks/{planner_task}/status', {'_csrf':csrf,'status':'open','return_view':'https://example.com'})[0] == 422 and db.execute('select status from tasks where id=?',(planner_task,)).fetchone()[0] == 'done', 'Invalid return view is rejected before task mutation')
            check(request(f'/planner/tasks/{planner_task}/delete', {'_csrf':csrf})[0] == 200 and db.execute('select id from tasks where id=?',(planner_task,)).fetchone() is None, 'Planner task delete')
            check(request('/planner/habits', {'_csrf':csrf,'title':'HTTP reading','notes':'Read daily'})[0] == 200, 'Habit create')
            habit = db.execute("select id from planner_habits where title='HTTP reading'").fetchone()[0]
            check(request(f'/planner/habits/{habit}/check', {'_csrf':csrf,'date':today})[0] == 200 and db.execute('select count(*) from planner_habit_checks where habit_id=?',(habit,)).fetchone()[0] == 1, 'Habit check-in')
            check(request(f'/planner/habits/{habit}/check', {'_csrf':csrf,'date':today})[0] == 200 and db.execute('select count(*) from planner_habit_checks where habit_id=?',(habit,)).fetchone()[0] == 0, 'Habit check-in undo')
            check(request(f'/planner/habits/{habit}/edit', {'_csrf':csrf,'title':'Paused habit','notes':'','active':'0'})[0] == 200, 'Habit edit and pause')
            check(request(f'/planner/habits/{habit}/check', {'_csrf':csrf,'date':today})[0] == 422, 'Paused habit cannot check in')
            check(request(f'/planner/habits/{habit}/delete', {'_csrf':csrf})[0] == 200, 'Habit delete')

            routine_fields = {'_csrf':csrf,'title':'HTTP routine','time':'08:00','days[]':[str(now.isoweekday())]}
            check(request('/planner/routines', routine_fields)[0] == 200, 'Routine create with selected weekdays')
            routine = db.execute("select id from planner_routines where title='HTTP routine'").fetchone()[0]
            check(request(f'/planner/routines/{routine}/check', {'_csrf':csrf,'date':today})[0] == 200 and db.execute('select count(*) from planner_routine_checks where routine_id=?',(routine,)).fetchone()[0] == 1, 'Routine scheduled completion')
            check(request(f'/planner/routines/{routine}/edit', dict(routine_fields, title='Updated routine', active='1'))[0] == 200, 'Routine edit')
            check(request(f'/planner/routines/{routine}/delete', {'_csrf':csrf})[0] == 200 and db.execute('select count(*) from planner_routine_checks where routine_id=?',(routine,)).fetchone()[0] == 0, 'Routine delete cascades check-ins')
            appointment_fields = {'_csrf':csrf,'title':'HTTP appointment','starts_at':today+'T09:00','ends_at':today+'T10:00','reminder_minutes':'30','location':'Library'}
            check(request('/planner/appointments', appointment_fields)[0] == 200, 'Appointment create')
            appointment = db.execute("select id from planner_appointments where title='HTTP appointment'").fetchone()[0]
            check(request(f'/planner/appointments/{appointment}/edit', dict(appointment_fields, title='Updated appointment', status='done'))[0] == 200, 'Appointment edit and complete')
            check(request(f'/planner/appointments/{appointment}/delete', {'_csrf':csrf})[0] == 200, 'Appointment delete')

            goal_fields = {'_csrf':csrf,'title':'HTTP personal goal','category':'education','horizon':'short','status':'active'}
            check(request('/growth', goal_fields)[0] == 200, 'Personal goal create')
            personal_goal = db.execute("select id from personal_goals where title='HTTP personal goal'").fetchone()[0]
            check(request(f'/growth/{personal_goal}/steps', {'_csrf':csrf,'title':'First milestone','is_milestone':'1'})[0] == 200, 'Goal milestone create')
            first_step = db.execute('select id from personal_goal_steps where goal_id=?',(personal_goal,)).fetchone()[0]
            check(request(f'/growth/{personal_goal}/steps', {'_csrf':csrf,'title':'Dependent step','depends_on':first_step})[0] == 200, 'Dependent step create')
            second_step = db.execute('select max(id) from personal_goal_steps where goal_id=?',(personal_goal,)).fetchone()[0]
            check(request(f'/growth/{personal_goal}/steps/{second_step}/status', {'_csrf':csrf,'status':'done'})[0] == 422, 'Unfinished prerequisite blocks completion')
            for step in [first_step, second_step]:
                check(request(f'/growth/{personal_goal}/steps/{step}/status', {'_csrf':csrf,'status':'done'})[0] == 200, 'Goal step complete')
            check(request(f'/growth/{personal_goal}/edit', dict(goal_fields, status='done'))[0] == 200 and db.execute('select status from personal_goals where id=?',(personal_goal,)).fetchone()[0] == 'done', 'Goal explicit completion')
            check(request(f'/growth/{personal_goal}/edit', goal_fields)[0] == 200, 'Goal reopen')
            check(request(f'/growth/{personal_goal}/steps/{second_step}/delete', {'_csrf':csrf})[0] == 200, 'Goal step delete')
            check(request(f'/growth/{personal_goal}/delete', {'_csrf':csrf})[0] == 200 and db.execute('select count(*) from personal_goal_steps where goal_id=?',(personal_goal,)).fetchone()[0] == 0, 'Goal deletion cascades remaining steps')

            check(request('/career/skills', {'_csrf':csrf,'name':'HTTP communication','progress':'20'})[0] == 200, 'Career skill create')
            skill = db.execute("select id from career_skills where name='HTTP communication'").fetchone()[0]
            check(request(f'/career/skills/{skill}/edit', {'_csrf':csrf,'name':'HTTP communication','progress':'40'})[0] == 200, 'Career skill edit')
            check(request('/career/courses', {'_csrf':csrf,'name':'HTTP course','skill_id':skill,'status':'completed','progress':'10'})[0] == 200, 'Career course create')
            course = db.execute("select id from career_courses where name='HTTP course'").fetchone()[0]
            check(db.execute('select progress from career_courses where id=?',(course,)).fetchone()[0] == 100, 'Completed course progress normalized')
            check(request(f'/career/courses/{course}/edit', {'_csrf':csrf,'name':'HTTP course','skill_id':skill,'status':'in_progress','progress':'50'})[0] == 200, 'Career course reopen and edit')
            check(request('/career/applications', {'_csrf':csrf,'organization':'HTTP organization','role':'Intern','status':'applied','applied_date':today})[0] == 200, 'Career application create')
            application = db.execute("select id from career_applications where organization='HTTP organization'").fetchone()[0]
            check(request(f'/career/applications/{application}/edit', {'_csrf':csrf,'organization':'HTTP organization','role':'Intern','status':'interview','interview_at':(now+timedelta(days=1)).strftime('%Y-%m-%dT%H:%M')})[0] == 200, 'Career application interview edit')
            for entity, identifier in [('skills',skill),('courses',course),('applications',application)]:
                check(request(f'/career/{entity}/{identifier}/delete', {'_csrf':csrf})[0] == 200, 'Career delete ' + entity)

            check(request('/focus/start', {'_csrf':csrf,'subject':'HTTP focus','minutes':'1','kind':'study'})[0] == 200, 'Focus timer start')
            focus = db.execute("select id from focus_sessions where subject='HTTP focus'").fetchone()[0]
            check(request('/focus/start', {'_csrf':csrf,'subject':'Duplicate','minutes':'1','kind':'study'})[0] == 422, 'Only one active focus session')
            for action, state in [('pause','paused'),('resume','running'),('finish','complete')]:
                check(request(f'/focus/{focus}/action', {'_csrf':csrf,'action':action})[0] == 200 and db.execute('select status from focus_sessions where id=?',(focus,)).fetchone()[0] == state, 'Focus timer ' + action)
            check(request('/insights/review', {'_csrf':csrf,'month':month,'wins':'HTTP wins','lessons':'Reflect','focus':'Practice','score':'8'})[0] == 200, 'Monthly review save')
            check(request('/insights/review', {'_csrf':csrf,'month':month,'wins':'Updated wins','score':'9'})[0] == 200 and db.execute('select count(*) from monthly_life_reviews where month=?',(month,)).fetchone()[0] == 1, 'Monthly review upserts')
            check(request('/insights/notifications', {'_csrf':csrf,'enabled':'1'})[0] == 200, 'Enable reminder preference')
            code, alerts_body, headers, _ = request('/insights/alerts')
            alerts = json.loads(alerts_body)
            check(code == 200 and 'application/json' in headers['Content-Type'] and alerts['enabled'] and len(alerts['alerts']) > 0, 'Reminder API reports real overdue records')
            alert_id = alerts['alerts'][0]['id']
            check(request(f'/insights/alerts/{alert_id}/dismiss', {'_csrf':csrf})[0] == 200 and all(a['id'] != alert_id for a in json.loads(request('/insights/alerts')[1])['alerts']), 'Reminder dismiss persists')
            check(request('/insights/notifications', {'_csrf':csrf})[0] == 200 and not json.loads(request('/insights/alerts')[1])['enabled'], 'Disable Windows reminder preference')

            savings_account = db.execute("select id from accounts where kind='savings' order by id limit 1").fetchone()[0]
            plan_fields = {'_csrf':csrf,'month':month,'income':'1000.01','bills':'100','loan_payments':'50','living':'200','savings_percent':'20','investment_percent':'10','savings_step_percent':'1','emergency_account_id':savings_account,'emergency_months':'3'}
            code, preview, _, _ = request('/salary/plan/preview', plan_fields, {'HX-Request':'true'})
            check(code == 200 and b'id="plan-preview"' in preview and b'350.01' in preview and b'<!doctype' not in preview, 'Live salary plan preview uses cent-accurate math')
            income_before_plan = db.execute('select count(*) from income').fetchone()[0]
            check(request('/salary/plan', plan_fields)[0] == 200 and db.execute('select count(*) from income').fetchone()[0] == income_before_plan, 'Salary planning saves without fabricating transactions')
            check(b'12-month contribution plan' in request('/salary/plan?month='+month)[1], 'Salary projection renders')
            check(db.execute('select savings_step_percent from salary_plans where month=?',(month,)).fetchone()[0] == 1 and b'31.00%' in request('/salary/plan?month='+month)[1], 'Savings target grows month by month')
            check(b'months of essential costs covered' in request('/freedom')[1], 'Freedom runway uses a saved plan and real savings')
            check(request('/salary/plan/allocations', {'_csrf':csrf,'month':month,'spending_account_id':'1','savings_account_id':savings_account,'investment_asset_id':asset})[0] == 200, 'Connect salary plan to real allocation destinations')
            balances_before = db.execute('select id,balance from accounts order by id').fetchall()
            check(request('/salary', {'_csrf':csrf,'date':today,'source':'HTTP investment salary','amount':'1000.01','type':'salary'})[0] == 200, 'Salary with linked investment allocation')
            linked_income = db.execute("select id from income where source='HTTP investment salary'").fetchone()[0]
            linked_deposit = db.execute('select asset_id,amount from asset_transactions where income_id=?',(linked_income,)).fetchone()
            check(linked_deposit is not None and linked_deposit[0] == asset and round(linked_deposit[1]*100) == 10000, 'Salary investment share arrives as an asset deposit')
            check(request(f'/salary/{linked_income}/delete', {'_csrf':csrf})[0] == 200 and db.execute('select count(*) from asset_transactions where income_id=?',(linked_income,)).fetchone()[0] == 0 and db.execute('select id,balance from accounts order by id').fetchall() == balances_before, 'Salary deletion reverses linked investments and account credits')
            obligation_fields = {'_csrf':csrf,'title':'HTTP bill','kind':'bill','amount':'3.25','due_date':today,'interval':'none','account_id':'1','category_id':'1'}
            check(request('/salary/obligations', obligation_fields)[0] == 200, 'Financial commitment create')
            obligation = db.execute("select id from financial_obligations where title='HTTP bill'").fetchone()[0]
            check(request(f'/salary/obligations/{obligation}/pay', {'_csrf':csrf,'due_date':today,'paid_date':today})[0] == 200, 'Financial commitment payment')
            expense_id = db.execute('select expense_id from financial_payments where obligation_id=?',(obligation,)).fetchone()[0]
            check(request(f'/salary/obligations/{obligation}/pay', {'_csrf':csrf,'due_date':today,'paid_date':today})[0] == 200 and db.execute('select count(*) from financial_payments where obligation_id=?',(obligation,)).fetchone()[0] == 1, 'Payment retry is idempotent')
            check(request(f'/expenses/{expense_id}/delete', {'_csrf':csrf})[0] == 200 and db.execute('select active from financial_obligations where id=?',(obligation,)).fetchone()[0] == 1, 'Deleting paid expense restores reminder')
            recurrence_fields = {'_csrf':csrf,'title':'HTTP recurring income','kind':'income','amount':'7.01','income_type':'salary','interval':'monthly','next_date':today,'active':'1'}
            check(request('/salary/recurring', recurrence_fields)[0] == 200, 'Recurring income creates and processes due entry')
            recurrence = db.execute("select id from financial_recurrences where title='HTTP recurring income'").fetchone()[0]
            check(db.execute('select count(*) from financial_occurrences where recurrence_id=?',(recurrence,)).fetchone()[0] == 1, 'Due recurrence records one occurrence')
            check(request('/salary/recurring/run', {'_csrf':csrf})[0] == 200 and db.execute('select count(*) from financial_occurrences where recurrence_id=?',(recurrence,)).fetchone()[0] == 1, 'Recurring retries cannot duplicate income')
            check(request(f'/salary/recurring/{recurrence}/status', {'_csrf':csrf,'active':'0'})[0] == 200, 'Recurring income pause')

            document_bytes = b'Acceptance document with UTF-8 notes.\n'
            check(multipart('/documents/upload', {'_csrf':csrf,'title':'HTTP document','category':'University','notes':'Test notes'}, 'document','notes.txt',document_bytes)[0] == 200, 'Document multipart upload')
            document = db.execute("select id from documents where title='HTTP document'").fetchone()[0]
            check(request(f'/documents/{document}/download')[1] == document_bytes, 'Document download is byte-exact')
            check(request(f'/documents/{document}/update', {'_csrf':csrf,'title':'Updated HTTP document','category':'Certificates','notes':'Updated notes'})[0] == 200, 'Document metadata edit')
            check(b'Updated HTTP document' in request('/documents?q=Updated&category=Certificates')[1], 'Document search and category filters')
            check(multipart('/documents/upload', {'_csrf':csrf,'title':'Invalid upload','category':'Other'}, 'document','notes.pdf',document_bytes)[0] == 422, 'Document type/content mismatch rejected')
            check(request(f'/documents/{document}/trash', {'_csrf':csrf})[0] == 200 and b'Updated HTTP document' in request('/documents?trash=1')[1], 'Document recoverable Trash')
            check(request(f'/documents/{document}/restore', {'_csrf':csrf})[0] == 200 and db.execute('select deleted_at from documents where id=?',(document,)).fetchone()[0] is None, 'Document restore from Trash')
            phrase = 'http acceptance backup passphrase'
            code, encrypted, _, _ = request('/settings/encrypted/export', {'_csrf':csrf,'passphrase':phrase,'passphrase_confirm':phrase})
            check(code == 200 and encrypted.startswith(b'LIFEOS-E1\n') and b'SQLite format 3' not in encrypted, 'Encrypted complete backup export')
            check(request(f'/documents/{document}/update', {'_csrf':csrf,'title':'Changed after backup','category':'Other','notes':''})[0] == 200, 'Modify document after backup')
            check(multipart('/settings/encrypted/restore', {'_csrf':csrf,'confirm':'RESTORE','passphrase':'incorrect long passphrase'}, 'backup','backup.lifeosbk',encrypted)[0] == 422 and db.execute('select title from documents where id=?',(document,)).fetchone()[0] == 'Changed after backup', 'Wrong backup passphrase preserves current records')
            db.close()
            check(multipart('/settings/encrypted/restore', {'_csrf':csrf,'confirm':'RESTORE','passphrase':phrase}, 'backup','backup.lifeosbk',encrypted)[0] == 200, 'Encrypted database and document restore')
            db = sqlite3.connect(Path(data)/'lifeos.db')
            csrf = token(request('/settings')[1])
            check(db.execute('select title from documents where id=?',(document,)).fetchone()[0] == 'Updated HTTP document' and request(f'/documents/{document}/download')[1] == document_bytes, 'Encrypted restore rolls back metadata and preserves document bytes')
            check(len(list((Path(data)/'backups').glob('before-encrypted-restore-*'))) == 1, 'Encrypted restore creates safety snapshot')
            for table in ['salary_plans','freedom_time','planner_habits','personal_goals','career_skills','focus_sessions','monthly_life_reviews','documents']:
                check(request('/settings/export/'+table)[0] == 200, 'CSV export includes ' + table)
            uni = project/'app/Modules/Uni'
            disabled = project/'app/Modules/Uni.disabled'
            uni.rename(disabled)
            check(request('/')[0] == 200 and b'University GPA' in request('/')[1], 'Dashboard survives disabled Uni module')
            disabled.rename(uni)
            code, exported, _, _ = request("/settings/export", {"_csrf":csrf})
            check(code == 200 and exported.startswith(b"SQLite format 3"), "Consistent database export")
            check(request("/settings/pin", {"_csrf":csrf,"pin":"1234","pin_confirm":"1234"})[0] == 200, "Set optional PIN")
            server.terminate()
            server.wait(timeout=10)
            server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "public", "public/index.php"], cwd=project, env=env, stdout=log, stderr=log)
            for _ in range(100):
                try:
                    if request('/health')[0] == 200: break
                except OSError: pass
                time.sleep(.1)
            code, locked, _, url = request('/salary')
            check(url.endswith('/unlock'), 'Restart requires PIN even with an existing session cookie')
            csrf = token(locked)
            request('/unlock', {'_csrf':csrf,'pin':'1234'})
            csrf = token(request('/settings')[1])
            request("/lock", {"_csrf":csrf})
            code, locked, _, url = request("/salary")
            check(url.endswith("/unlock") and b"Unlock LifeOS" in locked, "PIN guards money")
            check(b'/assets/brand/lifeos-mark.png' in locked and b'/assets/theme.js' in locked, 'Unlock screen retains logo and selected theme')
            csrf = token(locked)
            check(request("/unlock", {"_csrf":csrf,"pin":"9999"})[0] == 422, "Wrong PIN rejected")
            check(request("/unlock", {"_csrf":csrf,"pin":"1234"})[0] == 200, "Unlock success")
            csrf = token(request("/settings")[1])
            boundary = "lifeosAcceptanceBoundary"
            raw = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name=\"confirm\"\r\n\r\nRESTORE\r\n--{boundary}\r\nContent-Disposition: form-data; name=\"database\"; filename=\"backup.db\"\r\nContent-Type: application/octet-stream\r\n\r\n".encode()+exported+f"\r\n--{boundary}--\r\n".encode())
            db.close()
            check(request("/settings/restore", headers={"Content-Type":f"multipart/form-data; boundary={boundary}"}, raw=raw)[0] == 200, "Upload and restore backup")
            db = sqlite3.connect(Path(data)/"lifeos.db")
            check(db.execute("select count(*) from settings where key='pin_hash'").fetchone()[0] == 0, "Restore replaces settings")
            db.close()
            check(len(list((Path(data)/"backups").glob("*before-restore*"))) == 1, "Restore preserves safety backup")
            check(not (Path(data)/"php_error.log").exists(), "No application errors")
            print(f"{checks} HTTP acceptance checks passed.")
        finally:
            if 'db' in locals():
                db.close()
            if 'website_db' in locals():
                website_db.close()
            server.terminate()
            server.wait(timeout=10)

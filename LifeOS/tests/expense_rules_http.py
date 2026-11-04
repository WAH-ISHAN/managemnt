"""Disposable HTTP check for reviewed expense category suggestions and posting."""
import datetime
import http.cookiejar
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
checks=0
def check(value,label):
    global checks
    if not value: raise AssertionError(label)
    checks+=1

with tempfile.TemporaryDirectory(prefix='lifeos-expense-rules-http-') as temporary:
    data=Path(temporary)
    project=data/'project'
    for folder in ['app','public']: shutil.copytree(ROOT/folder,project/folder)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',0))
        port=probe.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    browser=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path,fields=None,headers=None):
        body=urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            with browser.open(urllib.request.Request(base+path,body,headers or {}),timeout=15) as response:
                return response.status,response.read(),response.url
        except urllib.error.HTTPError as error:
            return error.code,error.read(),error.url
    def read(sql,params=()):
        database=sqlite3.connect(data/'lifeos.db')
        try:
            return database.execute(sql,params).fetchall()
        finally:
            database.close()
    with (data/'server.log').open('w') as log:
        server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=project,env=dict(os.environ,LIFEOS_DATA_DIR=temporary),stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    if request('/health')[0]==200: break
                except OSError: pass
                time.sleep(.1)
            code,page,_=request('/salary/expense-rules')
            check(code==200 and b'Expense category rules' in page,'Rule workspace loads without existing financial history')
            csrf=re.search(rb'name="_csrf" value="([a-f0-9]+)"',page).group(1).decode()
            categories={name:identity for identity,name in read('SELECT id,name FROM expense_categories')}
            account=read('SELECT id FROM accounts ORDER BY id LIMIT 1')[0][0]
            check(request('/salary/expense-rules',{'keyword':'bus fare','category':categories['Transport'],'priority':10,'enabled':1})[0]==419,'Rule writes require CSRF')
            code,_,url=request('/salary/expense-rules',{'_csrf':csrf,'keyword':'bus fare','category':categories['Transport'],'priority':10,'enabled':1})
            check(code==200 and url.endswith('/salary/expense-rules'),'A rule saves and returns to the workspace')
            check(len(read('SELECT id FROM expense_category_rules'))==1,'The rule persists in the database')
            code,page,_=request('/salary/expense-rules/preview',{'_csrf':csrf,'description':'Paid BUS fare today'})
            check(code==200 and b'Matched the rule' in page and b'Transport' in page,'Preview explains the category match')
            check(len(read('SELECT id FROM expenses'))==0 and read('SELECT balance FROM accounts WHERE id=?',(account,))[0][0]==0,'Preview never posts or debits an expense')
            code,page,_=request('/salary/expense-rules/preview',{'_csrf':csrf,'description':'No keyword here'})
            check(code==200 and b'Uncategorized' in page and b'Choose category' in page,'Unmatched text requires a category choice')
            def save_rule(keyword,category,priority=100,identity=None,enabled=True):
                fields={'_csrf':csrf,'keyword':keyword,'category':category,'priority':priority}
                if identity is not None: fields['id']=identity
                if enabled: fields['enabled']='1'
                return request('/salary/expense-rules',fields)
            def preview(description):
                return request('/salary/expense-rules/preview',{'_csrf':csrf,'description':description})
            def reviewed_category(page):
                form=re.search(rb'<form method="post" action="/expenses".*?</form>',page,re.S)
                if form is None: return None
                category_select=re.search(rb'<select name="category".*?</select>',form.group(),re.S)
                selected=re.search(rb'<option value="([0-9]+)" selected>',category_select.group())
                return int(selected.group(1)) if selected else None
            transport_rule=read('SELECT id FROM expense_category_rules')[0][0]
            overlap=save_rule('bus fare snack',categories['Food & groceries'],9)
            check(overlap[0]==200 and reviewed_category(preview('bus fare snack')[1])==categories['Food & groceries'],'Overlapping phrase selects explicitly higher priority')
            overlap_id=read("SELECT id FROM expense_category_rules WHERE keyword='bus fare snack'")[0][0]
            save_rule('bus fare snack',categories['Food & groceries'],10,overlap_id)
            check(reviewed_category(preview('bus fare snack')[1])==categories['Transport'],'Equal priority uses deterministic earlier ID')
            save_rule('bus fare',categories['Transport'],10,transport_rule,False)
            check(reviewed_category(preview('bus fare snack')[1])==categories['Food & groceries'],'Paused earlier rule cannot override enabled overlap')
            save_rule('bus fare',categories['Transport'],10,transport_rule,True)
            before_rules=read('SELECT * FROM expense_category_rules ORDER BY id')
            check(save_rule('BUS FARE',categories['Food & groceries'],1,overlap_id)[0]==422,'Editing to a duplicate rejected over HTTP')
            check(read('SELECT * FROM expense_category_rules ORDER BY id')==before_rules,'Failed duplicate edit leaves both rules unchanged')
            for invalid in [b'bad\xc3\x28', 'bad\x00text', 'bad\x1btext']:
                check(save_rule(invalid,categories['Transport'])[0]==422,'Malformed text rejected on HTTP save')
                check(preview(invalid)[0]==422,'Malformed text rejected on HTTP preview')
            for invalid in [{'keyword':'x'}, {'keyword':'x'*81}, {'priority':1000}, {'category':999999}, {'id':999999}]:
                fields={'_csrf':csrf,'keyword':'valid keyword','category':categories['Transport'],'priority':10,'enabled':1}
                fields.update(invalid)
                check(request('/salary/expense-rules',fields)[0]==422,'Invalid rule payload rejected over HTTP')
            check(request('/salary/expense-rules',{'_csrf':csrf,'keyword':'cross origin','category':categories['Transport'],'priority':10},{'Origin':'https://outside.example'})[0]==403,'Cross-origin writes blocked even with a CSRF value')
            check(save_rule('කඩ',categories['Food & groceries'])[0]==200,'Sinhala rule saves')
            check(reviewed_category(preview('කඩ ගෙවීම')[1])==categories['Food & groceries'],'Sinhala word preview selects category')
            check(reviewed_category(preview('කඩේ ගෙවීම')[1]) is None,'Sinhala vowel sign prevents partial-word match')
            check(save_rule('பட',categories['Food & groceries'])[0]==200,'Tamil rule saves')
            check(reviewed_category(preview('பட செலவு')[1])==categories['Food & groceries'],'Tamil word preview selects category')
            check(reviewed_category(preview('படி செலவு')[1]) is None,'Tamil vowel sign prevents partial-word match')
            payload='<svg/onload=alert(1)>'
            check(save_rule(payload,categories['Transport'])[0]==200,'Literal markup keyword saves as text')
            code,escaped,_=preview(payload)
            check(code==200 and b'&lt;svg/onload=alert(1)&gt;' in escaped and payload.encode() not in escaped,'Stored keyword and preview description are HTML escaped')
            check(read('SELECT * FROM expenses')==[] and all(balance==0 for (balance,) in read('SELECT balance FROM accounts')),'All edits, rejected forms and Unicode previews leave finances untouched')

            today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=5,minutes=30))).date().isoformat()
            code,_,url=request('/expenses',{'_csrf':csrf,'date':today,'amount':'12.50','category':categories['Food & groceries'],'account':account,'note':'Paid BUS fare today'})
            check(code==200 and url.endswith('/salary'),'User override posts through the existing expense route')
            check(read('SELECT category_id,amount FROM expenses')==[(categories['Food & groceries'],12.5)] and read('SELECT balance FROM accounts WHERE id=?',(account,))[0][0]==-12.5,'Posted expense uses the reviewed category and debits once')
            before_delete=read('SELECT * FROM expenses'),read('SELECT * FROM accounts')
            check(request(f'/salary/expense-rules/{transport_rule}/delete',{'_csrf':csrf})[0]==200,'Rule delete succeeds')
            check((read('SELECT * FROM expenses'),read('SELECT * FROM accounts'))==before_delete,'Rule delete preserves posted expense and balance')
            check(request(f'/salary/expense-rules/{transport_rule}/delete',{'_csrf':csrf})[0]==422,'Repeated delete rejected safely')
            check(request('/salary/alert-import')[0]==404,'Removed bank-alert page is unavailable')
            check(request('/salary/alert-import/preview',{'_csrf':csrf,'alert':'debit LKR 1.00'})[0]==404,'Removed bank parser cannot be invoked')
            check(len(read('SELECT id FROM expenses'))==1,'Removed bank endpoint makes no financial writes')
            check(not (data/'php_error.log').exists() or not (data/'php_error.log').read_text(errors='replace'),'HTTP suite produced no PHP errors or regex warnings')
            print(f'{checks} expense rules HTTP checks passed.')
        finally:
            server.terminate()
            server.wait(timeout=10)

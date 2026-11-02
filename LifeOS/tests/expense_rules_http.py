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
    def request(path,fields=None):
        body=urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            with browser.open(urllib.request.Request(base+path,body),timeout=15) as response:
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
            today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=5,minutes=30))).date().isoformat()
            code,_,url=request('/expenses',{'_csrf':csrf,'date':today,'amount':'12.50','category':categories['Food & groceries'],'account':account,'note':'Paid BUS fare today'})
            check(code==200 and url.endswith('/salary'),'User override posts through the existing expense route')
            check(read('SELECT category_id,amount FROM expenses')==[(categories['Food & groceries'],12.5)] and read('SELECT balance FROM accounts WHERE id=?',(account,))[0][0]==-12.5,'Posted expense uses the reviewed category and debits once')
            print(f'{checks} expense rules HTTP checks passed.')
        finally:
            server.terminate()
            server.wait(timeout=10)

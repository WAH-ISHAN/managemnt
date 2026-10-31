"""Profile the actual desktop process tree and local PHP server using disposable data.
Requires psutil (`python -m pip install psutil`). Run after build.ps1.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
import http.cookiejar
import json
import math
import os
from pathlib import Path
import re
import statistics
import subprocess
import threading
import time
import uuid
import urllib.request
import urllib.parse
import psutil

ROOT=Path(__file__).resolve().parents[1]
MIB=1024*1024
def percentile(values,p):
    return sorted(values)[max(0,math.ceil(len(values)*p)-1)] if values else 0

@contextmanager
def profile_data_directory():
    # Python's Windows mkdtemp ACL can exclude WebView's filtered browser token
    # when an elevated process owns the directory as Administrators. A normal
    # directory inherits the test workspace's permissions, like Ui-Preflight.
    base=(ROOT/'tests/.tmp').resolve()
    base.mkdir(parents=True,exist_ok=True)
    directory=(base/('profile-'+uuid.uuid4().hex)).resolve()
    if directory.parent != base:
        raise RuntimeError('Unsafe profile test-data directory.')
    directory.mkdir()
    print('Isolated profile data:',directory,flush=True)
    try:
        yield directory
    finally:
        # Preserve both successes and failures for inspection; all rows are fictional.
        print('Profile data preserved:',directory,flush=True)
def run():
    parser=argparse.ArgumentParser()
    parser.add_argument('--exe',type=Path,default=ROOT/'dist/LifeOS.exe')
    parser.add_argument('--output',type=Path,default=ROOT/'performance-results.json')
    args=parser.parse_args()
    samples=[]; workloads=[]; baseline=[]; previous={}; observed={}; manual_stage=None
    stop=threading.Event(); process=None
    report={'timestamp':time.strftime('%Y-%m-%d %H:%M:%S'),'logical_cpus':psutil.cpu_count(),'physical_ram_mib':round(psutil.virtual_memory().total/MIB,1)}
    print('Measuring 5-second system baseline...',flush=True)
    for _ in range(5):
        baseline.append({'cpu':psutil.cpu_percent(interval=1),'used_ram_mib':psutil.virtual_memory().used/MIB,'available_ram_mib':psutil.virtual_memory().available/MIB})
    report['baseline']={'system_cpu_mean_pct':round(statistics.mean(x['cpu'] for x in baseline),2),'used_ram_mib':round(statistics.mean(x['used_ram_mib'] for x in baseline),1),'available_ram_mib':round(statistics.mean(x['available_ram_mib'] for x in baseline),1)}
    with profile_data_directory() as data:
        report['data_directory']=str(data)
        seed=subprocess.run(['php',str(ROOT/'tests/seed_load.php'),str(data)],capture_output=True,text=True,check=True)
        report['seed_rows']=json.loads(seed.stdout)
        print('Seeded',sum(report['seed_rows'].values()),'synthetic rows.',flush=True)
        env=dict(os.environ,LIFEOS_PROFILE_TEST='1',LIFEOS_DATA_DIR=str(data))
        env.pop('LIFEOS_SMOKE_TEST',None)
        start=time.perf_counter()
        process=subprocess.Popen([str(args.exe.resolve())],cwd=args.exe.resolve().parent,env=env)
        parent=psutil.Process(process.pid)
        def stage_file():
            try: return json.loads((data/'profile-stage.json').read_text())
            except (OSError,json.JSONDecodeError): return {}
        def monitor():
            last=time.perf_counter()
            psutil.cpu_percent()
            while not stop.wait(.25):
                now=time.perf_counter(); cpu=0; rss=0; private=0; count=0
                try: children=[parent]+parent.children(recursive=True)
                except psutil.Error: children=[]
                for child in children:
                    try:
                        with child.oneshot():
                            identity=(child.pid,child.create_time()); times=child.cpu_times(); total=times.user+times.system; memory=child.memory_info()
                            cpu+=max(0,total-previous.get(identity,0)); previous[identity]=total
                            rss+=memory.rss; private+=getattr(memory,'private',memory.rss); count+=1; observed[identity]=child.name()
                    except psutil.Error: pass
                memory=psutil.virtual_memory(); stage=manual_stage or stage_file().get('stage','startup')
                samples.append({'elapsed_s':round(now-start,3),'stage':stage,'app_cpu_pct':cpu/(now-last)/psutil.cpu_count()*100,'app_rss_mib':rss/MIB,'app_private_mib':private/MIB,'process_count':count,'system_cpu_pct':psutil.cpu_percent(),'system_used_ram_mib':memory.used/MIB,'system_available_ram_mib':memory.available/MIB})
                last=now
        thread=threading.Thread(target=monitor,daemon=True); thread.start()
        try:
            deadline=time.monotonic()+75
            last_stage=None
            while True:
                state=stage_file()
                if state.get('stage') != last_stage and state:
                    print('Desktop stage:',state.get('stage'),flush=True); last_stage=state.get('stage')
                if state.get('stage')=='load': break
                if process.poll() is not None or time.monotonic()>deadline:
                    error=(data/'profile-error.txt').read_text() if (data/'profile-error.txt').exists() else 'Desktop exited or timed out.'
                    raise RuntimeError(error)
                stop.wait(.2)
            report['startup_to_idle_s']=next((x['elapsed_s'] for x in samples if x['stage']=='idle'),None)
            base=f"http://127.0.0.1:{state['port']}"
            proxy=urllib.request.ProxyHandler({})
            def get(path):
                t=time.perf_counter()
                try:
                    with urllib.request.build_opener(proxy).open(base+path,timeout=30) as response:
                        body=response.read(); status=response.status
                    return {'ms':(time.perf_counter()-t)*1000,'ok':status==200,'bytes':len(body)}
                except Exception as error: return {'ms':(time.perf_counter()-t)*1000,'ok':False,'error':str(error)}
            paths=['/','/salary','/investments','/investments/1','/uni','/lessons','/review','/settings']
            for workers,count in [(1,32),(4,64),(8,96)]:
                manual_stage=f'reads_{workers}_clients'; print(manual_stage,flush=True); t=time.perf_counter()
                with ThreadPoolExecutor(max_workers=workers) as pool: results=list(pool.map(get,[paths[i%len(paths)] for i in range(count)]))
                elapsed=time.perf_counter()-t; latency=[r['ms'] for r in results]
                workloads.append({'name':manual_stage,'requests':count,'concurrency':workers,'errors':sum(not r['ok'] for r in results),'elapsed_s':round(elapsed,3),'requests_per_second':round(count/elapsed,2),'p50_ms':round(percentile(latency,.5),2),'p95_ms':round(percentile(latency,.95),2),'max_ms':round(max(latency),2)})
            manual_stage='writes_4_clients'; print(manual_stage,flush=True)
            def write(index):
                opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                with opener.open(base+'/salary') as r: token=re.search(rb'name="_csrf" value="([a-f0-9]+)"',r.read()).group(1).decode()
                body=urllib.parse.urlencode({'_csrf':token,'date':time.strftime('%Y-%m-%d'),'source':'Load test write','type':'salary','amount':'100.01'}).encode()
                t=time.perf_counter()
                try:
                    with opener.open(urllib.request.Request(base+'/salary',body),timeout=30) as r: r.read(); ok=r.status==200
                    return {'ms':(time.perf_counter()-t)*1000,'ok':ok}
                except Exception as error: return {'ms':(time.perf_counter()-t)*1000,'ok':False,'error':str(error)}
            t=time.perf_counter()
            with ThreadPoolExecutor(max_workers=4) as pool: results=list(pool.map(write,range(40)))
            latency=[r['ms'] for r in results]; elapsed=time.perf_counter()-t
            workloads.append({'name':manual_stage,'requests':40,'concurrency':4,'errors':sum(not r['ok'] for r in results),'elapsed_s':round(elapsed,3),'requests_per_second':round(40/elapsed,2),'p50_ms':round(percentile(latency,.5),2),'p95_ms':round(percentile(latency,.95),2),'max_ms':round(max(latency),2)})
            import sqlite3
            with sqlite3.connect(data/'lifeos.db') as db:
                rows=db.execute("SELECT id,amount FROM income WHERE source='Load test write'").fetchall()
                valid=all(round(db.execute('SELECT SUM(amount) FROM allocations WHERE income_id=?',(row[0],)).fetchone()[0]*100)==10001 for row in rows)
                report['write_integrity']={'saved_incomes':len(rows),'expected':40,'allocations_correct':valid,'sqlite_integrity':db.execute('PRAGMA integrity_check').fetchone()[0]}
            manual_stage='recovery'; print('Measuring 8-second recovery...',flush=True); stop.wait(8)
            manual_stage='shutdown'; (data/'profile-stop').touch(); process.wait(timeout=20)
            time.sleep(1)
            report['php_exited']=not psutil.pid_exists(state['phpPid'])
            report['application_error_log']=(data/'php_error.log').read_text() if (data/'php_error.log').exists() else ''
        finally:
            if process.poll() is None:
                process.terminate(); process.wait(timeout=10)
            stop.set(); thread.join(timeout=5)
            remaining=[]
            for identity in observed:
                try:
                    child=psutil.Process(identity[0])
                    if child.create_time()==identity[1]: remaining.append(child)
                except psutil.Error: pass
            _, alive=psutil.wait_procs(remaining,timeout=5)
            for child in alive:
                try: child.kill()
                except psutil.Error: pass
        report['workloads']=workloads
        stages={}
        for stage in dict.fromkeys(x['stage'] for x in samples):
            group=[x for x in samples if x['stage']==stage]
            stages[stage]={key:round(statistics.mean(x[key] for x in group),2) for key in ['app_cpu_pct','app_rss_mib','app_private_mib','system_cpu_pct','system_used_ram_mib','system_available_ram_mib']}
            stages[stage].update({'peak_app_cpu_pct':round(max(x['app_cpu_pct'] for x in group),2),'peak_app_rss_mib':round(max(x['app_rss_mib'] for x in group),2),'peak_app_private_mib':round(max(x['app_private_mib'] for x in group),2),'samples':len(group),'max_processes':max(x['process_count'] for x in group)})
        report['stages']=stages; report['samples']=samples; report['process_names']=sorted(set(observed.values()))
        args.output.write_text(json.dumps(report,indent=2),encoding='utf-8')
        print(json.dumps({k:v for k,v in report.items() if k not in ['samples','seed_rows']},indent=2),flush=True)
        if any(w['errors'] for w in workloads) or not report.get('php_exited') or report.get('application_error_log') or report['write_integrity']['saved_incomes']!=40 or not report['write_integrity']['allocations_correct']: raise SystemExit(1)
if __name__=='__main__': run()

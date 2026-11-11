"""Offline contracts for the installed CLI integration; no network/model downloads."""
import importlib.util,json,tempfile,time
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('lifeos_worker',ROOT/'scripts/opencode_worker.py');worker=importlib.util.module_from_spec(spec);spec.loader.exec_module(worker)
checks=0
def check(ok,label):
    global checks
    assert ok,label
    checks+=1
def reject(callback,label):
    try:callback()
    except RuntimeError:check(True,label);return
    raise AssertionError(label)
def tier(input=0,output=0,read=0,write=0,**extra):return dict(input=input,output=output,cache={'read':read,'write':write},**extra)
def model(name,cost=None,**extra):return dict(id=name,name=name,providerID='opencode',enabled=True,status='active',cost=[tier()]if cost is None else cost,capabilities={'input':['text'],'output':['text']},**extra)
model_metadata=model # Runner callbacks receive a parameter named model.
def catalog(*models):return json.dumps({'location':{'directory':'C:/private'},'data':models})
model_output=catalog(model('free'),model('paid-free',[tier(input=1)]),model('unknown',[]),model('cached-paid',[tier(read=1)]),model('paid-tier',[tier(),tier(output=1,tier={'type':'context','size':200000})]))
check([m['id']for m in worker.free_models(model_output)]==['opencode/free'],'Free suffix and one free tier never authorize paid usage')
reject(lambda:worker.free_models(catalog(model('paid',[tier(input=1)]))),'No priced fallback')
check(worker.free_models(catalog(model('tiered',[tier(),tier(tier={'type':'context','size':200000})])))[0]['id']=='opencode/tiered','Zero-cost context tiers can have nonzero thresholds')
for bad in [model('boolean',[tier(input=False)]),model('missing-price',[{'input':0,'output':0}]),model('unknown-price',[tier(image=2)]),dict(model('disabled'),enabled=False),dict(model('deprecated'),status='deprecated'),dict(model('other'),providerID='other'),dict(model('image'),capabilities={'input':['image'],'output':['image']})]:
    reject(lambda bad=bad:worker.free_models(catalog(bad)),'Unknown, nontext, disabled and other-provider models are refused')
reject(lambda:worker.free_models('opencode/free\n'),'Unpriced model names cannot become a verified catalog')
events='\n'.join(json.dumps(x)for x in [{'type':'step_start'},{'type':'text','part':{'id':'a','text':'Hello'}},{'type':'text','part':{'id':'a','text':'Hello'}},{'type':'text','part':{'id':'b','text':'LifeOS'}}])
check(worker.parse_events(events)=='Hello\nLifeOS','NDJSON text and duplicate IDs parsed')
reject(lambda:worker.parse_events('{"type":"error","error":{"message":"limited"}}'),'Provider errors cannot become answers')
reject(lambda:worker.parse_events('{"type":"tool_use","part":{"tool":"bash"}}'),'Agent tool event is refused')
check('quota' in worker.service_failure('AI_APICallError: Free usage limit reached.'),'Provider quota is identified without a long retry loop')
check(worker.service_failure('normal runtime startup') is None,'Startup log entries do not stop a healthy request')
check(worker.service_failure('timestamp=2026-11-03T10:11:12.429Z http.span=429 durationMs=429 id=job429')is None,'Tracing IDs, durations and timestamps containing 429 are not quota failures')
for failure in ['HTTP429','HTTP 429 Too Many Requests','HTTP/1.1 429','http.status=429','status code 429','{"statusCode":429}']:
    check('quota'in worker.service_failure(failure),'Explicit HTTP 429 statuses remain service-limit failures')
with tempfile.TemporaryDirectory()as temp:
    env=worker.environment(Path(temp),'opencode/free');config=json.loads(env['OPENCODE_CONFIG_CONTENT'])
    check('permissions'not in config and 'permissions'not in config['agents']['lifeos'],'Native tool definitions survive configuration so the CLI remains compatible with its service')
    check('steps'not in config['agents']['lifeos'],'Normal chat does not receive a maximum-steps summary instruction on its first reply')
    check({'action':'tool.use','resource':'*','effect':'deny'}in config['policies'],'Mandatory native policy denies every file/shell/web/subagent action at execution')
    check(config['policies'][:2]==[{'action':'provider.use','resource':'*','effect':'deny'},{'action':'provider.use','resource':'opencode','effect':'allow'}]and config['model']==config['agents']['title']['model']=='opencode/free','Main/title model constrained to verified provider')
    check(config['update']=='disable'and config['plugins']==[]and config['compaction']['auto']is False,'Native V2 background updates/plugins/compaction disabled')
    check(Path(env['OPENCODE_TEST_HOME']).parent==Path(temp)and Path(env['OPENCODE_CONFIG_DIR']).is_relative_to(temp),'Ambient personal agent/config discovery stays isolated')
    check(env['OPENCODE_DISABLE_AUTOUPDATE']=='true'and env['OPENCODE_DISABLE_LSP_DOWNLOAD']=='true'and env['OPENCODE_PURE']=='true','No automatic binary/plugin/LSP downloads')
    check(not any('API_KEY'in k for k in env),'Personal provider credentials are not inherited')
with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();calls=[]
    worker.atomic(root/'models.json',{'adapter':worker.ADAPTER_VERSION,'fetched_at':time.time(),'models':[{'id':'opencode/retired','name':'Old','input_cost':0,'output_cost':0}]})
    def rotated(executable,args,sandbox,model='',prompt=None,timeout=50):
        calls.append((args,model,prompt))
        if args[0]=='api':return model_output
        if model=='opencode/retired':raise RuntimeError('The free AI service is unavailable or limited. Refresh models or try later.')
        return json.dumps({'type':'text','part':{'id':'reply','text':'Your updated plan'}})
    model_output=catalog(model('current'),model('paid-free',[tier(input=2,output=1)]))
    job={'auto_model':True,'question':'Plan my day','context':{},'conversation':[{'role':'user','content':'I have one hour.'}]}
    check(worker.chat(job,Path('existing.exe'),sandbox,root,rotated)=='Your updated plan','Automatic refresh recovers when a model disappears')
    check(job['model_used']=='opencode/current'and [x[1]for x in calls if x[0][0]=='run']==['opencode/retired','opencode/current'],'Only verified zero-cost replacement runs')
    check('one hour' in calls[-1][2],'Bounded typed conversation reaches the assistant')
    check(all('--title' in args for args,_,_ in calls if args[0]=='run'),'No extra title-generation request is needed')
    check(all('--standalone'in args for args,_,_ in calls),'Each native call uses its private server and supplied isolation config')
    calls.clear();again={'auto_model':True,'question':'A different question','context':{}}
    def quota(executable,args,sandbox,model='',prompt=None,timeout=50):calls.append(model);raise RuntimeError('The free AI quota is exhausted. Try later or select another free model.')
    reject(lambda:worker.chat(again,Path('existing.exe'),sandbox,root,quota),'Quota is not bypassed by trying other models')
    check(calls==['opencode/current'],'A provider quota stops after one request')
    calls.clear();reject(lambda:worker.chat(again,Path('existing.exe'),sandbox,root,quota),'Quota cooldown remains visible without extra requests')
    check(not calls,'Repeated sends during provider cooldown use no network')
with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();calls=[];discovery=catalog(model('new-free'))
    # V1 metadata can contain stale successes and an obsolete runtime's quota.
    worker.atomic(root/'models.json',{'fetched_at':time.time(),'models':[{'id':'opencode/v1-old','input_cost':0,'output_cost':0}]})
    worker.atomic(root/'availability.json',{'quota_until':time.time()+3600,'last_success':'opencode/v1-old'})
    def initial(executable,args,sandbox,model='',prompt=None,timeout=50):
        calls.append(args);return discovery if args[0]=='api' else json.dumps({'type':'text','part':{'text':'Ready'}})
    job={'auto_model':True,'question':'Hello','context':{}}
    check(worker.chat(job,Path('existing.exe'),sandbox,root,initial)=='Ready'and [x[0]for x in calls]==['api','run'],'V2 refreshes V1 catalog and obsolete runtime cooldown without configuration UI')
    check(calls[0]==worker.MODEL_QUERY,'V2 catalog uses metadata API without unsupported V1 flags')
    check(worker.read_json(root/'models.json',{}).get('adapter')==worker.ADAPTER_VERSION and worker.read_json(root/'availability.json',{}).get('adapter')==worker.ADAPTER_VERSION,'Model and availability caches retain their runtime generation')
with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();calls=[]
    pool=catalog(*(model('free-'+str(i))for i in range(8)),model('paid-free',[tier(input=1)]))
    def whole_pool(executable,args,sandbox,model='',prompt=None,timeout=50):
        calls.append((args,model,prompt,timeout))
        if args[0]=='api':return pool
        if model!='opencode/free-7':raise RuntimeError('The AI service is temporarily unavailable.')
        return json.dumps({'type':'text','part':{'text':'Found an available model'}})
    job={'auto_model':True,'question':'Help me plan','context':{}}
    check(worker.chat(job,Path('existing.exe'),sandbox,root,whole_pool)=='Found an available model','Fallback can reach the eighth model rather than stopping after three')
    check(job['attempted_models']==['opencode/free-'+str(i)for i in range(8)],'Ordered diagnostics contain every attempted zero-cost model exactly once')
    check(sum(args[0]=='api'for args,_,_,_ in calls)==2,'Discovery is refreshed once during fallback without a request per model')
    check(len({prompt for args,_,prompt,_ in calls if args[0]=='run'})==1,'Fallback preserves the exact typed question/context/history')
    check(all(0<timeout<=worker.MODEL_TIMEOUT for args,_,_,timeout in calls if args[0]=='run'),'Every model attempt has a bounded timeout')
    check(all(name!='opencode/paid-free'for _,name,_,_ in calls),'Paid models are excluded even after exhausting the initial candidates')
    calls.clear()
    again={'auto_model':True,'question':'Another question','context':{}}
    check(worker.chat(again,Path('existing.exe'),sandbox,root,whole_pool)=='Found an available model'and again['attempted_models']==['opencode/free-7'],'Recent per-model failures are skipped on the next send')

with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();selected=[]
    pool=catalog(*(model('ready-'+str(i))for i in range(5)))
    def healthy(executable,args,sandbox,model='',prompt=None,timeout=50):
        if args[0]=='api':return pool
        selected.append(model);return json.dumps({'type':'text','part':{'text':'Ready'}})
    for i in range(6):worker.chat({'auto_model':True,'question':str(i),'context':{}},Path('existing.exe'),sandbox,root,healthy)
    check(selected==['opencode/ready-'+str(i%5)for i in range(6)],'Successful requests rotate fairly across the full free pool and wrap around')
    # Existing provider cooldowns survive this router update (same adapter).
    state=worker.read_json(root/'availability.json',{});state['quota_until']=time.time()+120;worker.atomic(root/'availability.json',state)
    selected.clear();reject(lambda:worker.chat({'auto_model':True,'question':'Blocked','context':{}},Path('existing.exe'),sandbox,root,healthy),'Existing provider quota remains in force')
    check(not selected,'Rotation never clears an active provider cooldown')

with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();calls=[]
    pool=catalog(*(model('ready-'+str(i))for i in range(5)))
    def limited(executable,args,sandbox,model='',prompt=None,timeout=50):
        calls.append(args[0])
        if args[0]=='api':return pool
        return json.dumps({'type':'error','error':{'name':'QuotaExceeded','message':'Free usage limit reached'}})
    job={'auto_model':True,'question':'Hello','context':{}}
    reject(lambda:worker.chat(job,Path('existing.exe'),sandbox,root,limited),'A provider quota event stops a multi-model request')
    check(calls==['api','run']and len(job['attempted_models'])==1,'No rotation or metadata retry after a provider quota response')
    calls.clear();reject(lambda:worker.chat(job,Path('existing.exe'),sandbox,root,limited),'Subsequent sends respect provider cooldown')
    check(calls==[],'Cooldown sends make no model or metadata requests')

with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();calls=[]
    worker.atomic(root/'models.json',{'adapter':worker.ADAPTER_VERSION,'fetched_at':time.time()-worker.CATALOG_TTL-1,'models':[{'id':'opencode/now-paid','input_cost':0,'output_cost':0}]})
    def repriced(executable,args,sandbox,model='',prompt=None,timeout=50):
        calls.append((args[0],model))
        if args[0]=='api':return catalog(model_metadata('now-paid',[tier(input=1)]),model_metadata('still-free'))
        return json.dumps({'type':'text','part':{'text':'Safe'}})
    worker.chat({'auto_model':True,'question':'Hello','context':{}},Path('existing.exe'),sandbox,root,repriced)
    check(calls==[('api',''),('run','opencode/still-free')],'Five-minute expiry rechecks pricing before sending and refuses a newly paid model')
    def down(*args,**kwargs):raise RuntimeError('Catalog unavailable')
    reject(lambda:worker.catalog(root,Path('existing.exe'),sandbox,down,refresh=True),'Failed refresh is reported')
    check(worker.read_json(root/'models.json',{})['models']==[],'A failed refresh invalidates the old pricing authorization')

with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();clock=[0.0];calls=[]
    def slow(executable,args,sandbox,model='',prompt=None,timeout=50):
        if args[0]=='api':return catalog(*(model_metadata('slow-'+str(i))for i in range(20)))
        calls.append((model,timeout));clock[0]+=timeout
        raise RuntimeError('AI busy or timed out. Try again later.')
    with patch.object(worker.time,'monotonic',side_effect=lambda:clock[0]):
        reject(lambda:worker.chat({'auto_model':True,'question':'Hello','context':{}},Path('existing.exe'),sandbox,root,slow),'Slow services cannot keep one chat pending indefinitely')
    check(clock[0]<=worker.CHAT_TIMEOUT and len(calls)<20,'The whole request respects its time budget even with a large model pool')
    check(calls[-1][1]<worker.MODEL_TIMEOUT,'The last attempt receives only the remaining time budget')

with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();calls=[]
    def tool_attempt(executable,args,sandbox,model='',prompt=None,timeout=50):
        calls.append(args[0])
        if args[0]=='api':return catalog(model_metadata('first'),model_metadata('second'))
        return json.dumps({'type':'tool_use','part':{'tool':'bash'}})
    reject(lambda:worker.chat({'auto_model':True,'question':'Hello','context':{}},Path('existing.exe'),sandbox,root,tool_attempt),'A denied tool is still a hard failure')
    check(calls==['api','run'],'Tool denial never causes model rotation')

with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();calls=[]
    def updated_agent(executable,args,sandbox,model='',prompt=None,timeout=50):
        if args[0]=='api':return catalog(model_metadata('one-free'))
        calls.append(model);return json.dumps({'type':'text','part':{'text':'Old response'if len(calls)==1 else 'Correct final answer'}})
    with patch.object(worker,'AGENT_VERSION','old'):
        worker.chat({'auto_model':True,'question':'Hello','context':{}},Path('existing.exe'),sandbox,root,updated_agent)
    with patch.object(worker,'AGENT_VERSION','new'):
        answer=worker.chat({'auto_model':True,'question':'Hello','context':{}},Path('existing.exe'),sandbox,root,updated_agent)
    check(answer=='Correct final answer'and len(calls)==2,'Agent updates invalidate old answer caches without resetting provider availability')

with tempfile.TemporaryDirectory()as temp:
    root=Path(temp);sandbox=root/'runtime';sandbox.mkdir();prompts=[]
    def dated_proposal(executable,args,sandbox,model='',prompt=None,timeout=50):
        if args[0]=='api':return catalog(model_metadata('one-free'))
        prompts.append(prompt)
        return json.dumps({'type':'text','part':{'text':json.dumps({'answer':'Review this task before saving.','actions':[{'action':'add_task','title':'Review proposal','due':None}]})}})
    job={'auto_model':True,'question':'Create a task called Review proposal, due tomorrow.','context':{}}
    with patch.object(worker.time,'strftime',return_value='2099-06-15'):
        first=worker.chat(dict(job),Path('existing.exe'),sandbox,root,dated_proposal)
        worker.chat(dict(job),Path('existing.exe'),sandbox,root,dated_proposal)
    check(len(prompts)==1 and prompts[0].startswith('Current local date: 2099-06-15\n'),'Ordinary chat supplies a local date for relative deadlines and retains same-day caching')
    with patch.object(worker.time,'strftime',return_value='2099-06-16'):
        second=worker.chat(dict(job),Path('existing.exe'),sandbox,root,dated_proposal)
    check(len(prompts)==2 and prompts[1].startswith('Current local date: 2099-06-16\n'),'The same tomorrow request after midnight cannot reuse a proposal based on yesterday')
    check(json.loads(first)['actions'][0]['action']=='add_task' and first==second,'Structured action JSON reaches the existing review parser without being replaced by narration')
    check(job['context']=={} and 'Explicit context: {}'in prompts[0],'The date metadata does not share any personal LifeOS records')
print(f'{checks} AI worker checks passed.')

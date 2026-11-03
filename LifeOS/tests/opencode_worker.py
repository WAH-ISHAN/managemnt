"""Offline contracts for the installed CLI integration; no network/model downloads."""
import importlib.util,json,tempfile
from pathlib import Path
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
def model(name,cost,**extra):return 'opencode/'+name+'\n'+json.dumps(dict(id=name,cost=cost,**extra))+'\n'
catalog=model('free',{'input':0,'output':0,'cache':{'read':0,'write':0}})+model('paid-free',{'input':1,'output':0})+model('unknown',{})+model('cached-paid',{'input':0,'output':0,'cache':{'read':1}})
check([m['id']for m in worker.free_models(catalog)]==['opencode/free'],'Free suffix alone never authorizes paid models')
reject(lambda:worker.free_models(model('paid',{'input':1,'output':1})),'No priced fallback')
events='\n'.join(json.dumps(x)for x in [{'type':'step_start'},{'type':'text','part':{'id':'a','text':'Hello'}},{'type':'text','part':{'id':'a','text':'Hello'}},{'type':'text','part':{'id':'b','text':'LifeOS'}}])
check(worker.parse_events(events)=='Hello\nLifeOS','NDJSON text and duplicate IDs parsed')
reject(lambda:worker.parse_events('{"type":"error","error":{"message":"limited"}}'),'Provider errors cannot become answers')
reject(lambda:worker.parse_events('{"type":"tool_use","part":{"tool":"bash"}}'),'Agent tool event is refused')
check('quota' in worker.service_failure('AI_APICallError: Free usage limit reached.'),'Provider quota is identified without a long retry loop')
check(worker.service_failure('normal runtime startup') is None,'Startup log entries do not stop a healthy request')
with tempfile.TemporaryDirectory()as temp:
    env=worker.environment(Path(temp),'opencode/free');config=json.loads(env['OPENCODE_CONFIG_CONTENT'])
    check(config['permission']=={'*':'deny'}and config['agent']['lifeos']['permission']=={'*':'deny'},'All model tools denied')
    check(not any(config['agent']['lifeos']['tools'].values()),'No file/shell/web/subagent tools')
    check(config['enabled_providers']==['opencode']and config['model']==config['small_model']=='opencode/free','Main/title model constrained to verified provider')
    check(env['OPENCODE_DISABLE_AUTOUPDATE']=='true'and env['OPENCODE_DISABLE_LSP_DOWNLOAD']=='true'and env['OPENCODE_PURE']=='true','No automatic binary/plugin/LSP downloads')
    check(not any('API_KEY'in k for k in env),'Personal provider credentials are not inherited')
print(f'{checks} AI worker checks passed.')

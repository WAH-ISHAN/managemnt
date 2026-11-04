"""Contracts for profile-isolated official OAuth, without using anybody's credentials."""
import importlib.util,json,os,tempfile,time
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('worker',ROOT/'scripts/opencode_worker.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
checks=0
def check(ok,label):
 global checks
 assert ok,label
 checks+=1
def reject(fn,label):
 try:fn()
 except (RuntimeError,ValueError):check(True,label);return
 raise AssertionError(label)
for bad in ['http://opencode.ai/console','https://opencode.ai.attacker.invalid/','https://evil.invalid/','https://user@opencode.ai/','https://opencode.ai:444/','file:///x','https://opencode.ai/\nspoof']:
 reject(lambda:w.authorization_url(bad),'Untrusted sign-in URL refused')
check(w.authorization_url('https://opencode.ai/console/device?code=TEST').startswith('https://'),'Official secure URL accepted')
with tempfile.TemporaryDirectory()as temp:
 root=Path(temp);directory=root/'ai';directory.mkdir();path=directory/'job.json';sandbox=root/'sandbox';sandbox.mkdir();calls=[];connections=[];flow=['complete'];cancelled=[]
 class Server:
  def __init__(self,*args):pass
  def __enter__(self):return self
  def __exit__(self,*args):cancelled.append('server stopped')
  def call(self,method,endpoint,value=None,**kwargs):
   calls.append((method,endpoint,value))
   if method=='GET'and endpoint=='/api/integration':return [{'id':'opencode','methods':[{'id':'device','type':'oauth'}],'connections':list(connections)}]
   if method=='POST':return {'attemptID':'attempt_qa','mode':'auto','url':'https://opencode.ai/console/device','instructions':'Enter code: QA-CODE','time':{'expires':(time.time()+60)*1000}}
   if method=='DELETE'and '/api/credential/'in endpoint:connections.clear();return
   if method=='DELETE':return
   status=flow[0]
   if status=='complete':connections.append({'id':'cred_qa','type':'credential','method':'oauth','label':'Private account label'})
   return {'status':status}
 job={'id':'qa','kind':'account_connect','status':'running'}
 w.account_job(job,Path('existing.exe'),sandbox,directory,path,Server)
 state=json.loads((directory/'account.json').read_text());check(state['connected'],'OAuth completion marks the current profile connected')
 check(not any(k in json.dumps(state)for k in ['Private account','access','refresh','token','cred_qa']),'Published state includes no credentials or private labels')
 check('authorization'not in job,'One-time URL removed from completed jobs')
 check(json.loads((directory/'models.json').read_text())['fetched_at']==0,'Connection expires pricing metadata for new account')
 check(any(c[0]=='DELETE'and 'attempt_qa'in c[1]for c in calls),'OAuth attempt is always closed')
 calls.clear();w.account_job(dict(job,kind='account_connect'),Path('existing.exe'),sandbox,directory,path,Server)
 check(not any(c[0]=='POST'for c in calls),'Connecting an existing account does not add duplicate credentials')
 w.atomic(directory/'availability.json',{'adapter':w.ADAPTER_VERSION,'quota_until':time.time()+120});before=(directory/'availability.json').read_bytes()
 w.account_job(dict(job,kind='account_disconnect'),Path('existing.exe'),sandbox,directory,path,Server)
 check(not connections and not w.read_json(directory/'account.json',{})['connected'],'Disconnect removes only the selected profile provider credentials')
 check((directory/'availability.json').read_bytes()==before,'Disconnect never resets provider quota cooldowns')
 check(len(cancelled)==3,'Private native server stopped after every operation')
 # Signing into another LifeOS account on the same Windows profile must not
 # inherit the former customer's native connection.
 connections.append({'id':'cred_qa','type':'credential','method':'oauth'})
 w.atomic(state_path:=directory/'account.json',{'connected':True,'owner':'customer-a','updated_at':time.time()})
 w.account_job(dict(job,kind='account_status',account_owner='customer-b'),Path('existing.exe'),sandbox,directory,path,Server)
 state=w.read_json(state_path,{})
 check(not state['connected']and state['owner']=='customer-a','Status cannot relabel an old customer credential as the new account')
 flow[0]='failed';calls.clear()
 reject(lambda:w.account_job(dict(job,account_owner='customer-b'),Path('existing.exe'),sandbox,directory,path,Server),'New customer must explicitly authorize their own connection')
 check(not connections and any(c[0]=='DELETE'and c[1]=='/api/credential/cred_qa'for c in calls),'Connecting a different LifeOS identity removes the previous native credential first')
 flow[0]='complete'
 # Cancellation is checked before beginning a fresh external OAuth attempt.
 calls.clear();path.with_suffix('.cancel').write_text('cancel')
 reject(lambda:w.account_job(dict(job),Path('existing.exe'),sandbox,directory,path,Server),'Queued cancellation is respected')
 check(not any(c[0]=='POST'for c in calls),'Cancelled queued sign-in makes no authorization request')
 path.with_suffix('.cancel').unlink();flow[0]='failed'
 failed=dict(job);reject(lambda:w.account_job(failed,Path('existing.exe'),sandbox,directory,path,Server),'Declined OAuth attempt reported as failure')
 check('authorization'not in failed,'Declined sign-in hides expired URL')
 check(not w.read_json(directory/'account.json',{})['connected'],'Declined sign-in does not claim a connection')
 profile_a=w.sandbox_for(root/'profile-a/ai');profile_b=w.sandbox_for(root/'profile-b/ai')
 check(profile_a!=profile_b,'Different LifeOS profiles use different native credential databases')
 with patch.dict(os.environ,{'OPENCODE_API_KEY':'owner-secret','GEMINI_API_KEY':'owner-google-secret'}):
  env=w.environment(sandbox);check('OPENCODE_API_KEY'not in env and 'GEMINI_API_KEY'not in env,'Owner credentials never inherited by account server/chat')
 calls.clear()
 def runner(*args,**kwargs):calls.append(args[1]);return json.dumps({'data':[{'id':'opencode','connections':[]}]})
 reject(lambda:w.chat({'require_account':True,'question':'Hello','auto_model':True},Path('existing.exe'),sandbox,directory,runner),'Commercial chat requires the customer connection')
 check(len(calls)==1 and calls[0][-1]=='/api/integration','Missing account makes no generation or model-pricing requests')
 target=root/'atomic.json';target.write_text('{"old":true}')
 original=Path.replace;attempts=[0]
 def sharing_violation(path,destination):
  attempts[0]+=1
  if attempts[0]<3:raise PermissionError('simulated Windows sharing violation')
  return original(path,destination)
 with patch.object(Path,'replace',sharing_violation):w.atomic(target,{'complete':True})
 check(json.loads(target.read_text())=={'complete':True}and attempts[0]==3,'Temporary Windows sharing violations do not strand an in-flight job')
check(True,'No AI runtime or weights installed by these checks')
print(f'{checks} AI account contracts passed.')

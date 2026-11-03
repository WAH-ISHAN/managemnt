"""Small, isolated OpenCode adapter. No package/model downloads or shell commands.
The desktop host starts this only for a queued, profile-scoped user request.
"""
import argparse,hashlib,json,os,re,subprocess,threading,time
from pathlib import Path

ADAPTER_VERSION='opencode-v2-1'
AGENT_VERSION='lifeos-chat-v2'
MODEL_QUERY=['api','--standalone','GET','/api/model']
CATALOG_TTL=300
CHAT_TIMEOUT=90
MODEL_TIMEOUT=20

class ToolRefused(RuntimeError):pass
def atomic(path,value):
    temporary=path.with_suffix('.tmp');temporary.write_text(json.dumps(value,ensure_ascii=False),encoding='utf-8');temporary.replace(path)
def environment(sandbox,model=''):
    keep={'PATH','SystemRoot','WINDIR','TEMP','TMP','LOCALAPPDATA','APPDATA','USERPROFILE','HOMEDRIVE','HOMEPATH','HTTP_PROXY','HTTPS_PROXY','NO_PROXY','SSL_CERT_FILE','SSL_CERT_DIR','COMSPEC','PATHEXT'}
    env={k:v for k,v in os.environ.items() if k.upper() in {x.upper() for x in keep}}
    for key,folder in [('XDG_CONFIG_HOME','config'),('XDG_DATA_HOME','data'),('XDG_CACHE_HOME','cache'),('XDG_STATE_HOME','state')]:
        path=sandbox/folder;path.mkdir(parents=True,exist_ok=True);env[key]=str(path)
    # V2 also discovers ~/.agents and ~/.claude outside XDG config roots.
    private_home=sandbox/'home';private_home.mkdir(parents=True,exist_ok=True)
    env['OPENCODE_TEST_HOME']=str(private_home)
    env['OPENCODE_CONFIG_DIR']=str(sandbox/'config'/'opencode')
    for flag in ['AUTOUPDATE','DEFAULT_PLUGINS','LSP_DOWNLOAD','CLAUDE_CODE','EXTERNAL_SKILLS','PROJECT_CONFIG','AUTOCOMPACT','TERMINAL_TITLE']:
        env['OPENCODE_DISABLE_'+flag]='true'
    env['OPENCODE_PURE']='true';env['OPENCODE_PERMISSION']='{"*":"deny"}'
    env['OPENCODE_DISABLE_FILEWATCHER']='true';env['OPENCODE_DISABLE_FFF']='true'
    env['OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX']='1024'
    # Keep OpenCode's native tool definitions available to the supported CLI.
    # Native tool.use policy denies execution; catch-all permission rules also
    # remove the definitions and make the service reject this configuration.
    config={'$schema':'https://opencode.ai/config.json','update':'disable','plugins':[],'instructions':[],
      'snapshots':False,'compaction':{'auto':False},
      'policies':[{'action':'provider.use','resource':'*','effect':'deny'},
                  {'action':'provider.use','resource':'opencode','effect':'allow'},
                  {'action':'tool.use','resource':'*','effect':'deny'}],
      'agents':{'lifeos':{'description':'LifeOS personal assistant','mode':'primary',
      'system':'You are the LifeOS assistant. Answer only the supplied question and explicit context. Your response is displayed directly in a chat panel: give only the final answer, without internal analysis or runtime instructions. Do not access files, commands, network tools or other agents. Never claim an action was executed. Answer directly and concisely in the user language. If proposing an action, emit only JSON with answer and actions. Allowed action kinds: add_task(title,due), log_expense(amount,date,note), summarize_week. No other actions. Financial context is absent unless explicitly supplied.'}}}
    if model:config['model']=model;config['agents']['title']={'model':model}
    env['OPENCODE_CONFIG_CONTENT']=json.dumps(config)
    return env
def service_failure(text):
    text=text.lower()
    # A bare 429 also occurs in timestamps, durations and tracing IDs. Only
    # explicit HTTP status/error contexts identify a service limit.
    limited=any(word in text for word in ['free usage limit','rate limit','quota'])
    http_limit=re.search(r'\bhttp(?:/\d(?:\.\d)?)?\s*429\b|\bstatus(?:_?code|\s+code)?[\"\x27]?\s*[:=]?\s*[\"\x27]?429\b|\b429\s+too\s+many\s+requests\b',text)
    if limited or http_limit:return 'The free AI quota is exhausted. Try later or select another free model.'
    if any(word in text for word in ['model is unavailable','model not found','model is not supported','endpoint is unavailable']):return 'The AI service is temporarily unavailable. Please try again shortly.'
    return None
def run(executable,args,sandbox,model='',prompt=None,timeout=50):
    service_log=sandbox/'data/opencode/log/opencode.log'
    log_offset=service_log.stat().st_size if service_log.exists() else 0
    flags=getattr(subprocess,'CREATE_NO_WINDOW',0)|getattr(subprocess,'BELOW_NORMAL_PRIORITY_CLASS',0)
    process=subprocess.Popen([str(executable),*args],cwd=sandbox,env=environment(sandbox,model),stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,creationflags=flags)
    buffers=[bytearray(),bytearray()];overflow=threading.Event()
    def read(pipe,index):
        while True:
            block=pipe.read(4096)
            if not block:break
            if len(buffers[index])+len(block)>2*1024*1024:overflow.set();process.kill();break
            buffers[index].extend(block)
    readers=[threading.Thread(target=read,args=(pipe,i),daemon=True) for i,pipe in enumerate([process.stdout,process.stderr])]
    for reader in readers:reader.start()
    try:
        if prompt:process.stdin.write(prompt.encode('utf-8'))
        process.stdin.close();deadline=time.monotonic()+timeout
        while process.poll() is None:
            if time.monotonic()>=deadline:raise subprocess.TimeoutExpired(process.args,timeout)
            if service_log.exists():
                with service_log.open('rb') as error_log:error_log.seek(log_offset);failure=service_failure(error_log.read(65536).decode('utf-8',errors='replace'))
                if failure:process.kill();process.wait(timeout=5);raise RuntimeError(failure)
            time.sleep(.2)
    except subprocess.TimeoutExpired:
        process.kill();process.wait(timeout=5)
        if service_log.exists():
            with service_log.open('rb') as error_log:error_log.seek(log_offset);failure=error_log.read(65536).decode('utf-8',errors='replace').lower()
            if service_failure(failure):raise RuntimeError(service_failure(failure))
        raise RuntimeError('AI busy or timed out. Try again later.')
    finally:
        for reader in readers:reader.join(timeout=3)
    if overflow.is_set():raise RuntimeError('The AI response exceeded the output limit.')
    stdout=buffers[0].decode('utf-8',errors='replace');stderr=buffers[1].decode('utf-8',errors='replace')
    if process.returncode:
        failure=(stdout+stderr).lower()
        if service_failure(failure):raise RuntimeError(service_failure(failure))
        raise RuntimeError('The free AI service is unavailable or limited. Refresh models or try later.')
    return stdout
def free_models(output):
    # `models` no longer prints pricing in V2. Its local API exposes every
    # pricing tier, including cache charges; a free suffix is never evidence.
    try:response=json.loads(output)
    except ValueError:raise RuntimeError('The installed AI runtime did not return a verified model catalog.')
    entries=response.get('data') if isinstance(response,dict) else None
    if not isinstance(entries,list):raise RuntimeError('The installed AI runtime did not return a verified model catalog.')
    models=[]
    def zero(value):return type(value) in (int,float) and value==0
    def free_tier(tier):
        if not isinstance(tier,dict) or not zero(tier.get('input')) or not zero(tier.get('output')):return False
        cache=tier.get('cache')
        if not isinstance(cache,dict) or not zero(cache.get('read')) or not zero(cache.get('write')):return False
        # Tier thresholds are metadata, not prices. Unknown price dimensions
        # fail closed until this adapter can verify their meaning.
        return not (set(tier)-{'input','output','cache','tier'}) and not (set(cache)-{'read','write'})
    for metadata in entries:
        if not isinstance(metadata,dict):continue
        identity=metadata.get('id');cost=metadata.get('cost');cap=metadata.get('capabilities',{})
        if metadata.get('providerID')!='opencode' or not isinstance(identity,str) or not re.fullmatch(r'[A-Za-z0-9_.:-]+',identity):continue
        if metadata.get('enabled') is not True or metadata.get('status') not in ['active','alpha','beta'] or metadata.get('type','chat')!='chat':continue
        if not isinstance(cap,dict) or not all(isinstance(cap.get(k),list) and 'text' in cap[k] for k in ['input','output']):continue
        if isinstance(cost,list) and cost and all(free_tier(tier) for tier in cost):
            models.append({'id':'opencode/'+identity,'name':metadata.get('name',identity),'input_cost':0,'output_cost':0})
    if not models:raise RuntimeError('No verified zero-cost text models are available. Refresh later.')
    return models
def parse_events(output):
    texts=[];seen=set()
    for line in output.splitlines():
        try:event=json.loads(line)
        except ValueError:continue
        if not isinstance(event,dict):continue
        if event.get('type')=='error':raise RuntimeError(service_failure(json.dumps(event.get('error',{}))) or 'The free AI service is busy or unavailable. Try later.')
        if event.get('type')=='tool_use':raise ToolRefused('The assistant attempted an unavailable tool. No LifeOS action was applied.')
        if event.get('type')=='text':
            part=event.get('part',{});text=part.get('text','');identity=part.get('id')
            if isinstance(text,str) and text and (not identity or identity not in seen):texts.append(text);seen.add(identity)
    answer='\n'.join(texts).strip()
    if not answer or len(answer)>30000:raise RuntimeError('The AI did not return a readable answer.')
    return answer
def read_json(path,default):
    try:
        if path.stat().st_size>200000:return default
        value=json.loads(path.read_text(encoding='utf-8'));return value if isinstance(value,type(default)) else default
    except (OSError,ValueError):return default
def catalog(directory,executable,sandbox,runner=run,refresh=False,timeout=25):
    path=directory/'models.json';cached=read_json(path,{})
    age=time.time()-cached.get('fetched_at',0)
    if refresh or cached.get('adapter')!=ADAPTER_VERSION or not 0<=age<CATALOG_TTL or not cached.get('models'):
        # A failed refresh must not leave an old zero-price authorization usable.
        atomic(path,{'adapter':ADAPTER_VERSION,'fetched_at':0,'models':[]})
        models=free_models(runner(executable,MODEL_QUERY,sandbox,timeout=timeout))
        atomic(path,{'adapter':ADAPTER_VERSION,'fetched_at':time.time(),'models':models})
    else:models=cached['models']
    return list(dict.fromkeys(m['id'] for m in models if isinstance(m,dict) and type(m.get('input_cost'))in(int,float) and m['input_cost']==0 and type(m.get('output_cost'))in(int,float) and m['output_cost']==0 and re.fullmatch(r'opencode/[A-Za-z0-9_.:-]+',m.get('id',''))))
def chat(job,executable,sandbox,directory,runner=run):
    deadline=time.monotonic()+CHAT_TIMEOUT
    availability_path=directory/'availability.json';availability=read_json(availability_path,{})
    if availability.get('adapter')!=ADAPTER_VERSION:availability={'adapter':ADAPTER_VERSION}
    if availability.get('quota_until',0)>time.time():raise RuntimeError('The free AI quota is exhausted. Try later or select another free model.')
    def discover(refresh=False):
        remaining=deadline-time.monotonic()
        if remaining<1:raise RuntimeError('AI busy or timed out. Try again later.')
        return catalog(directory,executable,sandbox,runner,refresh=refresh,timeout=min(15,remaining))
    models=discover();automatic=bool(job.get('auto_model'))
    preferred=job.get('model')
    if not automatic and preferred not in models:raise RuntimeError('Refresh the free model list before sending this question.')
    history=job.get('conversation',[])
    if not isinstance(history,list):history=[]
    history=[{'role':m['role'],'content':m['content'][:2000]}for m in history[-6:]if isinstance(m,dict)and m.get('role')in ['user','assistant']and isinstance(m.get('content'),str)]
    prompt='Conversation so far: '+json.dumps(history,ensure_ascii=False)+'\nQuestion: '+job['question']+'\nExplicit context: '+json.dumps(job.get('context',{}),ensure_ascii=False)
    tried=set();refreshed=False;failure=None;bad=availability.setdefault('unavailable_until',{})
    job['attempted_models']=[]
    def candidates():
        ordered=list(models)
        if automatic:
            # Round-robin across the whole live pool, rather than pinning every
            # question to the first model or the last successful model.
            previous=availability.get('last_selected',availability.get('last_success'))
            if previous in ordered:
                start=ordered.index(previous)+1;ordered=ordered[start:]+ordered[:start]
        elif preferred in ordered:ordered=[preferred]+[m for m in ordered if m!=preferred]
        return [m for m in ordered if m not in tried and bad.get(m,0)<=time.time()]
    while time.monotonic()<deadline-1:
        available=candidates()
        if not available and automatic and not refreshed:
            models=discover(refresh=True);refreshed=True;available=candidates()
        if not available:break
        model=available[0];tried.add(model);job['attempted_models'].append(model)
        availability['last_selected']=model;atomic(availability_path,availability)
        cache_key=hashlib.sha256((ADAPTER_VERSION+'\0'+AGENT_VERSION+'\0'+model+'\0'+prompt).encode()).hexdigest();response_cache=directory/('answer-'+cache_key+'.json')
        try:
            if response_cache.exists()and time.time()-response_cache.stat().st_mtime<900:
                answer=read_json(response_cache,{}).get('answer','')
                if not isinstance(answer,str)or not answer:raise RuntimeError('The AI did not return a readable answer.')
                job['cached']=True
            else:
                answer=parse_events(runner(executable,['run','--standalone','--format','json','--title','LifeOS assistant','--agent','lifeos','--model',model],sandbox,model,prompt,timeout=min(MODEL_TIMEOUT,deadline-time.monotonic())))
                atomic(response_cache,{'answer':answer})
            availability['last_success']=model;bad.pop(model,None);atomic(availability_path,availability)
            job['model_used']=model;return answer
        except RuntimeError as error:
            failure=error
            if 'quota' in str(error).lower():availability['quota_until']=time.time()+120;atomic(availability_path,availability);raise
            if isinstance(error,ToolRefused):raise
            bad[model]=time.time()+300;atomic(availability_path,availability)
            if automatic and not refreshed:models=discover(refresh=True);refreshed=True
    raise failure or RuntimeError('No verified zero-cost text models are available. Refresh later.')
def execute(job_path,executable):
    job_path=job_path.resolve();job=json.loads(job_path.read_text(encoding='utf-8'))
    if job.get('status')!='queued':return
    identity=hashlib.sha256(str(job_path.parent.parent).encode()).hexdigest()[:24]
    sandbox=Path(os.environ.get('LOCALAPPDATA',str(job_path.parent.parent)))/'LifeOS'/'ai-sandbox'/identity
    sandbox.mkdir(parents=True,exist_ok=True)
    lock=job_path.with_suffix('.lock')
    try:descriptor=os.open(lock,os.O_CREAT|os.O_EXCL|os.O_WRONLY);os.close(descriptor)
    except FileExistsError:return
    try:
        job['status']='running';job['started_at']=time.time();atomic(job_path,job)
        if job['kind']=='models':
            models=free_models(run(executable,MODEL_QUERY,sandbox,timeout=25))
            atomic(job_path.parent.parent/'models.json',{'adapter':ADAPTER_VERSION,'fetched_at':time.time(),'models':models});job['models']=models
        elif job['kind']=='chat':
            job['answer']=chat(job,executable,sandbox,job_path.parent.parent)
        else:raise RuntimeError('Unsupported AI request.')
        job['status']='complete'
    except Exception as error:
        job['status']='failed';job['error']=str(error) if isinstance(error,RuntimeError) else 'AI setup is unavailable. Refresh the model list or check the desktop runtime.'
    finally:
        job['finished_at']=time.time();atomic(job_path,job);lock.unlink(missing_ok=True)
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--job',type=Path,required=True);parser.add_argument('--executable',type=Path,required=True);args=parser.parse_args()
    if not args.executable.is_file() or args.executable.suffix.lower() not in ['.exe','']:raise SystemExit('An installed native executable is required.')
    execute(args.job,args.executable)

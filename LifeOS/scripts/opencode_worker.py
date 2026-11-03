"""Small, isolated OpenCode adapter. No package/model downloads or shell commands.
The desktop host starts this only for a queued, profile-scoped user request.
"""
import argparse,hashlib,json,os,re,subprocess,threading,time
from pathlib import Path

TOOLS=['bash','read','edit','write','apply_patch','glob','grep','list','task','todowrite','webfetch','websearch','lsp','skill','question']
def atomic(path,value):
    temporary=path.with_suffix('.tmp');temporary.write_text(json.dumps(value,ensure_ascii=False),encoding='utf-8');temporary.replace(path)
def environment(sandbox,model=''):
    keep={'PATH','SystemRoot','WINDIR','TEMP','TMP','LOCALAPPDATA','APPDATA','USERPROFILE','HOMEDRIVE','HOMEPATH','HTTP_PROXY','HTTPS_PROXY','NO_PROXY','SSL_CERT_FILE','SSL_CERT_DIR','COMSPEC','PATHEXT'}
    env={k:v for k,v in os.environ.items() if k.upper() in {x.upper() for x in keep}}
    for key,folder in [('XDG_CONFIG_HOME','config'),('XDG_DATA_HOME','data'),('XDG_CACHE_HOME','cache'),('XDG_STATE_HOME','state')]:
        path=sandbox/folder;path.mkdir(parents=True,exist_ok=True);env[key]=str(path)
    for flag in ['AUTOUPDATE','DEFAULT_PLUGINS','LSP_DOWNLOAD','CLAUDE_CODE','EXTERNAL_SKILLS','PROJECT_CONFIG','AUTOCOMPACT','TERMINAL_TITLE']:
        env['OPENCODE_DISABLE_'+flag]='true'
    env['OPENCODE_PURE']='true';env['OPENCODE_PERMISSION']='{"*":"deny"}'
    env['OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX']='1024'
    config={'$schema':'https://opencode.ai/config.json','autoupdate':False,'share':'disabled','plugin':[],'instructions':[],
      'enabled_providers':['opencode'],'permission':{'*':'deny'},'agent':{'lifeos':{'description':'LifeOS personal assistant','mode':'primary','steps':1,
      'prompt':'You are the LifeOS assistant. Answer only the supplied question and explicit context. Do not access files, commands, network tools or other agents. Never claim an action was executed. Answer in the user language. If proposing an action, emit only JSON with answer and actions. Allowed action kinds: add_task(title,due), log_expense(amount,date,note), summarize_week. No other actions. Financial context is absent unless explicitly supplied.',
      'tools':{tool:False for tool in TOOLS},'permission':{'*':'deny'}}}}
    if model:config['model']=model;config['small_model']=model
    env['OPENCODE_CONFIG_CONTENT']=json.dumps(config)
    return env
def service_failure(text):
    text=text.lower()
    if any(word in text for word in ['free usage limit','rate limit','quota','429']):return 'The free AI quota is exhausted. Try later or select another free model.'
    if 'model is unavailable' in text:return 'The free AI service is unavailable or limited. Refresh models or try later.'
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
    decoder=json.JSONDecoder();models=[];cursor=0
    while True:
        match=re.search(r'(?m)^opencode/([A-Za-z0-9_.:-]+)\s*\r?\n',output[cursor:])
        if not match:break
        start=cursor+match.end();key='opencode/'+match[1]
        try:metadata,size=decoder.raw_decode(output[start:].lstrip());cursor=start+len(output[start:])-len(output[start:].lstrip())+size
        except ValueError:cursor=start;continue
        cost=metadata.get('cost');cap=metadata.get('capabilities',{})
        def zero(value):return isinstance(value,dict) and all(zero(v) if isinstance(v,dict) else isinstance(v,(int,float)) and v==0 for v in value.values())
        if isinstance(cost,dict) and cost.get('input')==0 and cost.get('output')==0 and zero(cost) and metadata.get('status') not in ['deprecated','disabled'] and cap.get('input',{}).get('text',True):
            models.append({'id':key,'name':metadata.get('name',match[1]),'input_cost':0,'output_cost':0})
    if not models:raise RuntimeError('No verified zero-cost text models are available. Refresh later.')
    return models
def parse_events(output):
    texts=[];seen=set()
    for line in output.splitlines():
        try:event=json.loads(line)
        except ValueError:continue
        if event.get('type')=='error':raise RuntimeError('The free AI service is busy or unavailable. Try later.')
        if event.get('type')=='tool_use':raise RuntimeError('The assistant attempted an unavailable tool. No LifeOS action was applied.')
        if event.get('type')=='text':
            part=event.get('part',{});text=part.get('text','');identity=part.get('id')
            if isinstance(text,str) and text and (not identity or identity not in seen):texts.append(text);seen.add(identity)
    answer='\n'.join(texts).strip()
    if not answer or len(answer)>30000:raise RuntimeError('The AI did not return a readable answer.')
    return answer
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
            models=free_models(run(executable,['models','opencode','--verbose','--refresh'],sandbox,timeout=25))
            atomic(job_path.parent.parent/'models.json',{'fetched_at':time.time(),'models':models});job['models']=models
        elif job['kind']=='chat':
            cache=json.loads((job_path.parent.parent/'models.json').read_text(encoding='utf-8'))
            models=[m['id'] for m in cache['models'] if m.get('input_cost')==0 and m.get('output_cost')==0]
            if time.time()-cache['fetched_at']>86400 or job.get('model') not in models:raise RuntimeError('Refresh the free model list before sending this question.')
            prompt='Question: '+job['question']+'\nExplicit context: '+json.dumps(job.get('context',{}),ensure_ascii=False)
            model=job['model'];cache_key=hashlib.sha256((model+'\0'+prompt).encode()).hexdigest();response_cache=job_path.parent.parent/('answer-'+cache_key+'.json')
            if response_cache.exists() and time.time()-response_cache.stat().st_mtime<900:
                result=json.loads(response_cache.read_text(encoding='utf-8'));job['answer']=result['answer'];job['cached']=True
            else:
                candidates=[model]+[candidate for candidate in models if candidate!=model][:2]
                failure=None
                for candidate in candidates:
                    try:
                        job['answer']=parse_events(run(executable,['run','--format','json','--title','LifeOS assistant','--agent','lifeos','--model',candidate],sandbox,candidate,prompt,timeout=20))
                        model=candidate;failure=None;break
                    except RuntimeError as error:
                        failure=error
                        if 'tool' in str(error).lower() or 'quota' in str(error).lower():break
                if failure:raise failure
                atomic(response_cache,{'answer':job['answer']})
            job['model_used']=model
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

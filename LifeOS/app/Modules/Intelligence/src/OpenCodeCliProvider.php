<?php
declare(strict_types=1);
final class OpenCodeCliProvider implements AiProvider {
 public static function directory():string{$root=defined('DATA')?DATA:(getenv('LIFEOS_DATA_DIR')?:throw new RuntimeException(t('A selected profile is required.')));$dir=$root.'/ai';if(!is_dir($dir.'/jobs'))mkdir($dir.'/jobs',0700,true);return $dir;}
 public function models():array{$path=self::directory().'/models.json';if(!is_file($path)||filesize($path)>100000)return [];$data=json_decode(file_get_contents($path),true,32);return is_array($data)&&time()-(int)($data['fetched_at']??0)<86400&&is_array($data['models']??null)?$data['models']:[];}
 public function refresh():string{return self::queue(['kind'=>'models']);}
 public function accountJob(string $action):string{
  if(!in_array($action,['connect','status','disconnect'],true))throw new InvalidArgumentException(t('Invalid AI account action.'));
  return self::queue(['kind'=>'account_'.$action]);
 }
 private static function queue(array $data):string{
  $data['account_owner']=AiAccountService::owner();
  if(!in_array($data['kind']??'',['account_status','account_disconnect'],true)&&class_exists('LicenseService')&&!LicenseService::allowed('ai'))throw new InvalidArgumentException(t('Your LifeOS account does not include AI access. Review Account & activation.'));
  if(($data['kind']??'')==='chat'&&AiAccountService::required()){
   if(!AiAccountService::connected())throw new InvalidArgumentException(t('Connect your own AI account from Settings to continue.'));
   $data['require_account']=true;
  }
  $directory=self::directory();$lock=fopen($directory.'/queue.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new InvalidArgumentException(t('The assistant is busy. Try again.'));
  try{foreach(glob($directory.'/jobs/*.json')?:[]as $file){if(filesize($file)>100000)continue;$old=json_decode(file_get_contents($file),true);if(is_array($old)&&in_array($old['status']??'',['queued','running'],true)&&time()-(int)($old['created_at']??0)<(($old['kind']??'')==='account_connect'?360:120))throw new InvalidArgumentException(t('Wait for the current assistant request to finish.'));}
   $id=bin2hex(random_bytes(16));$data=array_merge($data,['id'=>$id,'status'=>'queued','created_at'=>microtime(true)]);$encoded=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(strlen($encoded)>70000)throw new InvalidArgumentException(t('Selected content is too large. Shorten the text or exclude task records.'));file_put_contents($directory.'/jobs/'.$id.'.json',$encoded,LOCK_EX);return $id;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function submit(string $question,string $model,array $context=[]):string{
  $question=IntelligenceService::text($question,2000);$models=$this->models();if(!in_array($model,array_column($models,'id'),true))throw new InvalidArgumentException(t('Refresh and select an available free model.'));
  return self::queue(['kind'=>'chat','question'=>$question,'model'=>$model,'context'=>$context]);
 }
 public static function thread(bool $new=false):string{
  $id=Db::setting('assistant_thread_id');if($new||!preg_match('/^[a-f0-9]{32}$/D',$id)){$id=bin2hex(random_bytes(16));Db::set('assistant_thread_id',$id);}return $id;
 }
 public function conversation(?string $thread=null):array{
  $thread??=self::thread();$jobs=[];
  foreach(glob(self::directory().'/jobs/*.json')?:[]as $file){if(filesize($file)>200000)continue;$job=json_decode(file_get_contents($file),true,32);if(!is_array($job)||($job['kind']??'')!=='chat'||($job['thread_id']??'')!==$thread)continue;$jobs[]=$this->result((string)$job['id']);}
  usort($jobs,fn($a,$b)=>(($a['created_at']??0)<=>($b['created_at']??0))?:strcmp($a['id'],$b['id']));return array_slice($jobs,-40);
 }
 public function submitAutomatic(string $question):string{
  if(AiAccountService::required()&&!AiAccountService::connected())throw new InvalidArgumentException(t('Connect your own AI account from Settings to continue.'));
  $question=IntelligenceService::text($question,2000);$thread=self::thread();$history=[];
  foreach(array_slice($this->conversation($thread),-3)as $job){if(($job['status']??'')!=='complete')continue;$history[]=['role'=>'user','content'=>mb_substr($job['question'],0,1200)];$history[]=['role'=>'assistant','content'=>mb_substr(self::answer($job),0,1200)];}
  return self::queue(['kind'=>'chat','question'=>$question,'auto_model'=>true,'thread_id'=>$thread,'conversation'=>$history,'context'=>[]]+(AiAccountService::required()?['require_account'=>true]:[]));
 }
 public function understand(string $text,string $purpose,?int $source=null,bool $shareTasks=false):string{
  $text=IntelligenceService::text($text,20000);
  if(!in_array($purpose,['capture','notes'],true))throw new InvalidArgumentException(t('Choose a valid assistant task.'));
  $context=['today'=>date('Y-m-d'),'language'=>LocaleService::language(),'request'=>'Understand the supplied text in its language. Summarize notes briefly and propose concrete tasks. Resolve relative dates using today. Return JSON {answer:string,actions:[{action:add_task,title:string,due:YYYY-MM-DD|null} or {action:move_task,task_id:integer,due:YYYY-MM-DD}]}. Move only task IDs in the explicit task list. Do not invent obligations or dates.'];
  if($shareTasks){if(!module_enabled('Planner'))throw new InvalidArgumentException(t('Enable Planner.'));$context['open_tasks']=Db::all("SELECT id,title,due FROM tasks WHERE status='open' AND archived_at IS NULL ORDER BY id DESC LIMIT 100");}
  $scope=['purpose'=>$purpose,'source'=>$source,'source_hash'=>null,'tasks'=>$context['open_tasks'] ?? []];
  $scope['snapshots']=[];
  foreach($scope['tasks']as $shared){$task=PlannerService::task((int)$shared['id']);$scope['snapshots'][$shared['id']]=hash('sha256',json_encode([$task['title'],$task['due'],$task['deadline'],$task['status'],$task['archived_at'],Db::all('SELECT id,starts_at,ends_at,pinned FROM planner_blocks WHERE task_id=? ORDER BY id',[$shared['id']])],JSON_THROW_ON_ERROR));}
  if($source!==null)$scope['source_hash']=hash('sha256',IntelligenceService::sourceRow($source)['body']);
  return self::queue(['kind'=>'chat','question'=>$text,'auto_model'=>true,'context'=>$context,'workflow'=>$scope]);
 }
 public static function answer(array $job):string{
  $raw=trim((string)($job['answer']??''));$json=json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/','',$raw),true,16);
  if(is_array($json))return is_string($json['answer']??null)?$json['answer']:t('I could not finish that response. Please try rephrasing your request.');return $raw;
 }
 public function panelState():array{
  $messages=[];foreach($this->conversation()as $job){$actions=[];foreach(self::proposals($job)as $index=>$proposal){$proposal['index']=$index;$proposal['applied']=(bool)Db::one('SELECT 1 FROM ai_applied_actions WHERE job_id=? AND action_index=?',[$job['id'],$index]);$actions[]=$proposal;}
   $messages[]=['id'=>$job['id'],'question'=>$job['question'],'status'=>$job['status'],'answer'=>$job['status']==='complete'?self::answer($job):'','error'=>$job['status']==='failed'?t(str_contains(strtolower((string)($job['error']??'')),'quota')?'LifeOS AI has reached its current usage limit. Please try again later.':'LifeOS AI is temporarily unavailable. Please try again shortly.'):'','actions'=>$actions];
  }return ['thread'=>self::thread(),'messages'=>$messages,'accountRequired'=>AiAccountService::required(),'accountConnected'=>AiAccountService::connected()];
 }
 public function result(string $id):array{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException(t('Invalid assistant request.'));$path=self::directory().'/jobs/'.$id.'.json';if(!is_file($path)||filesize($path)>200000)throw new InvalidArgumentException(t('Assistant request not found.'));$data=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);if(in_array($data['status'],['queued','running'],true)&&time()-(int)$data['created_at']>(($data['kind']??'')==='account_connect'?360:120)){$data['status']='failed';$data['error']=t('The assistant request stopped. Restart the app and try again.');}return $data;}
 public static function context(bool $consent):array{
  if(!$consent)return [];$facts=IntelligenceService::facts();$facts['consent']='User explicitly included anonymized reporting totals';
  if(module_enabled('WorkRoles')&&class_exists('WorkRoleService')){$start=date('Y-m-d',strtotime('monday this week'));$rows=WorkRoleService::summary($start,date('Y-m-d'));$facts['week_start']=$start;$facts['role_totals']=array_map(fn($r)=>['recorded_minutes'=>(int)round($r['seconds']/60),'completed_tasks'=>$r['completed']],$rows);}
  return $facts;
 }
 public static function proposals(array $job):array{
  if(($job['status']??'')!=='complete')return [];$raw=trim((string)($job['answer']??''));$raw=preg_replace('/^```(?:json)?\s*|\s*```$/','',$raw);$data=json_decode($raw,true,16);if(!is_array($data)||!is_array($data['actions']??null)||!array_is_list($data['actions'])||count($data['actions'])>5)return [];
  $out=[];foreach($data['actions']as $index=>$action){if(!is_array($action)||!in_array($action['action']??'',['add_task','move_task','log_expense','summarize_week'],true))continue;try{
   if($action['action']==='add_task')$out[$index]=['action'=>'add_task','title'=>IntelligenceService::text($action['title']??'',200),'due'=>optional_date((string)($action['due']??''))];
   elseif($action['action']==='move_task'){
    $taskId=record_id($action['task_id']??'');$shared=array_column($job['workflow']['tasks']??[],null,'id');
    if(!isset($shared[$taskId]))continue;
    $out[$index]=['action'=>'move_task','task_id'=>$taskId,'title'=>$shared[$taskId]['title'],'due'=>valid_date((string)($action['due']??''))];
   }
   elseif($action['action']==='log_expense')$out[$index]=['action'=>'log_expense','amount'=>cents($action['amount']??'')/100,'date'=>valid_date((string)($action['date']??'')),'note'=>IntelligenceService::text($action['note']??'Expense',300)];
   else $out[$index]=['action'=>'summarize_week'];
  }catch(InvalidArgumentException $e){continue;}}
  return $out;
 }
 public function apply(string $id,int $index,array $edit):void{
  $job=$this->result($id);$proposal=self::proposals($job)[$index]??throw new InvalidArgumentException(t('This proposed action is not supported.'));
  if(isset($job['workflow'])){
   if(!in_array($proposal['action'],['add_task','move_task'],true))throw new InvalidArgumentException(t('This proposed action is not supported.'));
   $source=$job['workflow']['source']??null;
   if($source!==null&&!hash_equals($job['workflow']['source_hash'],hash('sha256',IntelligenceService::sourceRow((int)$source)['body'])))throw new InvalidArgumentException(t('The source changed. Create a fresh preview.'));
  }
  $claim=static function()use($id,$index,$job){if(isset($job['workflow']['source'])&&!hash_equals($job['workflow']['source_hash'],hash('sha256',IntelligenceService::sourceRow((int)$job['workflow']['source'])['body'])))throw new InvalidArgumentException(t('The source changed. Create a fresh preview.'));if(Db::one('SELECT job_id FROM ai_applied_actions WHERE job_id=? AND action_index=?',[$id,$index]))throw new InvalidArgumentException(t('This assistant action was already applied.'));Db::run('INSERT INTO ai_applied_actions(job_id,action_index) VALUES(?,?)',[$id,$index]);};
  if($proposal['action']==='add_task'){
   if(!module_enabled('Planner'))throw new InvalidArgumentException(t('Enable Planner to create a task.'));
   Db::tx(function()use($claim,$proposal,$edit,$job){$claim();$task=PlannerService::saveTask(null,['title'=>IntelligenceService::text($edit['title']??$proposal['title'],200),'due'=>optional_date((string)($edit['due']??$proposal['due']??''))]);if(isset($job['workflow']['source'])){$draft=Db::run("INSERT INTO intelligence_drafts(source_id,kind,payload,status,result) VALUES (?,'tasks',?,'applied',?)",[$job['workflow']['source'],json_encode([$proposal],JSON_THROW_ON_ERROR),json_encode([$task],JSON_THROW_ON_ERROR)]);Db::run('INSERT INTO intelligence_task_links(task_id,source_id,draft_id) VALUES (?,?,?)',[$task,$job['workflow']['source'],$draft]);}});
  }elseif($proposal['action']==='move_task'){
   if(!module_enabled('Planner'))throw new InvalidArgumentException(t('Enable Planner.'));
   Db::tx(function()use($claim,$proposal,$edit,$job){
    $task=PlannerService::task($proposal['task_id']);$shared=array_column($job['workflow']['tasks'],null,'id')[$task['id']];
    $snapshot=hash('sha256',json_encode([$task['title'],$task['due'],$task['deadline'],$task['status'],$task['archived_at'],Db::all('SELECT id,starts_at,ends_at,pinned FROM planner_blocks WHERE task_id=? ORDER BY id',[$task['id']])],JSON_THROW_ON_ERROR));
    if(!hash_equals($job['workflow']['snapshots'][$task['id']] ?? '',$snapshot))throw new InvalidArgumentException(t('A task changed after preview. Create a fresh preview.'));
    if($task['status']!=='open'||$task['archived_at']!==null||$task['title']!==$shared['title']||$task['due']!==$shared['due'])throw new InvalidArgumentException(t('A task changed after preview. Create a fresh preview.'));
    $due=valid_date((string)($edit['due']??$proposal['due']));$draft=IntelligenceService::movePreview('move task #'.$task['id'].' to '.$due);$claim();IntelligenceService::apply($draft,[],true);
   });
  }elseif($proposal['action']==='log_expense'){
   if(!module_enabled('Salary'))throw new InvalidArgumentException(t('Enable Money to record an expense.'));
   SalaryService::addExpense(valid_date((string)($edit['date']??$proposal['date'])),record_id($edit['category_id']??''),record_id($edit['account_id']??''),$edit['amount']??$proposal['amount'],IntelligenceService::text($edit['note']??$proposal['note'],300),fn($expense)=>$claim());
  }else throw new InvalidArgumentException(t('Open the weekly review to see recorded totals.'));
 }
}

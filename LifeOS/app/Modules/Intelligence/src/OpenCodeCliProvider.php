<?php
declare(strict_types=1);
final class OpenCodeCliProvider implements AiProvider {
 public static function directory():string{$root=defined('DATA')?DATA:(getenv('LIFEOS_DATA_DIR')?:throw new RuntimeException(t('A selected profile is required.')));$dir=$root.'/ai';if(!is_dir($dir.'/jobs'))mkdir($dir.'/jobs',0700,true);return $dir;}
 public function models():array{$path=self::directory().'/models.json';if(!is_file($path)||filesize($path)>100000)return [];$data=json_decode(file_get_contents($path),true,32);return is_array($data)&&time()-(int)($data['fetched_at']??0)<86400&&is_array($data['models']??null)?$data['models']:[];}
 public function refresh():string{return self::queue(['kind'=>'models']);}
 private static function queue(array $data):string{
  $directory=self::directory();$lock=fopen($directory.'/queue.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new InvalidArgumentException(t('The assistant is busy. Try again.'));
  try{foreach(glob($directory.'/jobs/*.json')?:[]as $file){if(filesize($file)>100000)continue;$old=json_decode(file_get_contents($file),true);if(is_array($old)&&in_array($old['status']??'',['queued','running'],true)&&time()-(int)($old['created_at']??0)<120)throw new InvalidArgumentException(t('Wait for the current assistant request to finish.'));}
   $id=bin2hex(random_bytes(16));$data=array_merge($data,['id'=>$id,'status'=>'queued','created_at'=>time()]);file_put_contents($directory.'/jobs/'.$id.'.json',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX);return $id;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function submit(string $question,string $model,array $context=[]):string{
  $question=IntelligenceService::text($question,2000);$models=$this->models();if(!in_array($model,array_column($models,'id'),true))throw new InvalidArgumentException(t('Refresh and select an available free model.'));
  return self::queue(['kind'=>'chat','question'=>$question,'model'=>$model,'context'=>$context]);
 }
 public function result(string $id):array{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException(t('Invalid assistant request.'));$path=self::directory().'/jobs/'.$id.'.json';if(!is_file($path)||filesize($path)>200000)throw new InvalidArgumentException(t('Assistant request not found.'));$data=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);if(in_array($data['status'],['queued','running'],true)&&time()-(int)$data['created_at']>120){$data['status']='failed';$data['error']=t('The assistant request stopped. Restart the app and try again.');}return $data;}
 public static function context(bool $consent):array{
  if(!$consent)return [];$facts=IntelligenceService::facts();$facts['consent']='User explicitly included anonymized reporting totals';
  if(module_enabled('WorkRoles')&&class_exists('WorkRoleService')){$start=date('Y-m-d',strtotime('monday this week'));$rows=WorkRoleService::summary($start,date('Y-m-d'));$facts['week_start']=$start;$facts['role_totals']=array_map(fn($r)=>['recorded_minutes'=>(int)round($r['seconds']/60),'completed_tasks'=>$r['completed']],$rows);}
  return $facts;
 }
 public static function proposals(array $job):array{
  if(($job['status']??'')!=='complete')return [];$raw=trim((string)($job['answer']??''));$raw=preg_replace('/^```(?:json)?\s*|\s*```$/','',$raw);$data=json_decode($raw,true,16);if(!is_array($data)||!is_array($data['actions']??null)||!array_is_list($data['actions'])||count($data['actions'])>5)return [];
  $out=[];foreach($data['actions']as $index=>$action){if(!is_array($action)||!in_array($action['action']??'',['add_task','log_expense','summarize_week'],true))continue;try{
   if($action['action']==='add_task')$out[$index]=['action'=>'add_task','title'=>IntelligenceService::text($action['title']??'',200),'due'=>optional_date((string)($action['due']??''))];
   elseif($action['action']==='log_expense')$out[$index]=['action'=>'log_expense','amount'=>cents($action['amount']??'')/100,'date'=>valid_date((string)($action['date']??'')),'note'=>IntelligenceService::text($action['note']??'Expense',300)];
   else $out[$index]=['action'=>'summarize_week'];
  }catch(InvalidArgumentException $e){continue;}}
  return $out;
 }
 public function apply(string $id,int $index,array $edit):void{
  $job=$this->result($id);$proposal=self::proposals($job)[$index]??throw new InvalidArgumentException(t('This proposed action is not supported.'));
  $claim=static function()use($id,$index){if(Db::one('SELECT job_id FROM ai_applied_actions WHERE job_id=? AND action_index=?',[$id,$index]))throw new InvalidArgumentException(t('This assistant action was already applied.'));Db::run('INSERT INTO ai_applied_actions(job_id,action_index) VALUES(?,?)',[$id,$index]);};
  if($proposal['action']==='add_task'){
   if(!module_enabled('Planner'))throw new InvalidArgumentException(t('Enable Planner to create a task.'));
   Db::tx(function()use($claim,$proposal,$edit){$claim();PlannerService::saveTask(null,['title'=>IntelligenceService::text($edit['title']??$proposal['title'],200),'due'=>optional_date((string)($edit['due']??$proposal['due']??''))]);});
  }elseif($proposal['action']==='log_expense'){
   if(!module_enabled('Salary'))throw new InvalidArgumentException(t('Enable Money to record an expense.'));
   SalaryService::addExpense(valid_date((string)($edit['date']??$proposal['date'])),record_id($edit['category_id']??''),record_id($edit['account_id']??''),$edit['amount']??$proposal['amount'],IntelligenceService::text($edit['note']??$proposal['note'],300),fn($expense)=>$claim());
  }else throw new InvalidArgumentException(t('Open the weekly review to see recorded totals.'));
 }
}

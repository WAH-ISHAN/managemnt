<?php
declare(strict_types=1);
final class WorkRoleService {
 public static function roles(bool $active=false):array{return Db::all('SELECT * FROM work_roles'.($active?' WHERE is_active=1':'').' ORDER BY id');}
 public static function role(int $id,bool $active=false):array{$r=Db::one('SELECT * FROM work_roles WHERE id=?'.($active?' AND is_active=1':''),[$id]);if(!$r)throw new InvalidArgumentException(t('Choose an active work role.'));return $r;}
 private static function text(mixed $v,int $max=200,bool $required=true):string{if(!is_string($v)||str_contains($v,"\0")||mb_strlen(trim($v))>$max||($required&&trim($v)===''))throw new InvalidArgumentException(t('Enter valid work-role text.'));return trim($v);}
 public static function saveRole(array $d,?int $id=null):int{
  $name=self::text($d['name']??'');$org=self::text($d['org']??'',200,false);$source=self::text($d['income_source']??'',200,false);$rate=cents($d['hourly_rate']??'0',true)/100;$color=$d['color']??'#0071e3';if(!is_string($color)||!preg_match('/^#[0-9a-f]{6}$/iD',$color))throw new InvalidArgumentException(t('Choose a role colour.'));
  $active=isset($d['is_active'])?1:0;if($id){self::role($id);if(!$active&&Db::one('SELECT id FROM role_time_logs WHERE role_id=? AND ended_at IS NULL',[$id]))throw new InvalidArgumentException(t('Stop this role timer before disabling the role.'));Db::run('UPDATE work_roles SET name=?,org=?,income_source=?,hourly_rate=?,color=?,is_active=? WHERE id=?',[$name,$org,$source,$rate,$color,$active,$id]);return $id;}
  return Db::run('INSERT INTO work_roles(name,org,income_source,hourly_rate,color,is_active) VALUES(?,?,?,?,?,?)',[$name,$org,$source,$rate,$color,$active]);
 }
 public static function templates():void{Db::tx(function(){foreach([
  'developer'=>['Developer','#0071e3',['Daily stand-up'=>15,'PR review'=>30,'Development / bug fixing'=>120,'Learning'=>30]],
  'qa'=>['QA','#7c3aed',['Daily stand-up'=>15,'Regression run'=>60,'Bug verification'=>45]],
  'devops'=>['DevOps / Support','#059669',['Service health review'=>20,'Support queue'=>45,'Deployment review'=>30]],
  'lead'=>['Lead / PM','#d97706',['Team priorities'=>20,'Blocker follow-up'=>30,'Delivery review'=>30]],
  'uni'=>['University','#dc2626',['Assignment progress'=>60,'Study / revision'=>30]],
  'venture'=>['Own venture','#db2777',['Customer / product work'=>60,'Business follow-up'=>30]]
 ]as $key=>[$name,$color,$items]){Db::run('INSERT OR IGNORE INTO work_roles(name,color,template_key) VALUES(?,?,?)',[$name,$color,$key]);$role=Db::one('SELECT id FROM work_roles WHERE template_key=?',[$key]);foreach($items as $title=>$minutes)Db::run("INSERT OR IGNORE INTO role_responsibilities(role_id,title,recurrence,est_minutes,template_key) VALUES(?,?,'daily',?,?)",[$role['id'],t($title),$minutes,$title]);}});}
 public static function responsibility(array $d,?int $id=null):int{
  $role=record_id($d['role_id']??'');self::role($role,true);$title=self::text($d['title']??'');$recurrence=$d['recurrence']??'';if(!in_array($recurrence,['daily','weekly','monthly'],true))throw new InvalidArgumentException(t('Choose daily, weekly or monthly.'));
  $minutes=int_range($d['est_minutes']??30,5,480);$week=int_range($d['week_day']??1,1,7);$month=int_range($d['month_day']??1,1,31);$active=isset($d['is_active'])?1:0;
  if($id){if(!Db::one('SELECT id FROM role_responsibilities WHERE id=? AND role_id=?',[$id,$role]))throw new InvalidArgumentException(t('Responsibility does not belong to this role.'));Db::run('UPDATE role_responsibilities SET title=?,recurrence=?,est_minutes=?,week_day=?,month_day=?,is_active=? WHERE id=?',[$title,$recurrence,$minutes,$week,$month,$active,$id]);return $id;}
  return Db::run('INSERT INTO role_responsibilities(role_id,title,recurrence,est_minutes,week_day,month_day,is_active) VALUES(?,?,?,?,?,?,?)',[$role,$title,$recurrence,$minutes,$week,$month,$active]);
 }
 public static function generate(?string $date=null):int{
  $date=valid_date($date??date('Y-m-d'));$day=new DateTimeImmutable($date);$created=0;
  Db::tx(function()use($date,$day,&$created){foreach(Db::all('SELECT r.* FROM role_responsibilities r JOIN work_roles w ON w.id=r.role_id WHERE r.is_active=1 AND w.is_active=1')as $r){
   if($r['recurrence']==='weekly'&&(int)$day->format('N')!==(int)$r['week_day'])continue;
   if($r['recurrence']==='monthly'&&(int)$day->format('j')!==min((int)$r['month_day'],(int)$day->format('t')))continue;
   $key=match($r['recurrence']){'weekly'=>$day->format('o-\WW'),'monthly'=>$day->format('Y-m'),default=>$date};
   Db::run('INSERT OR IGNORE INTO work_tasks(role_id,responsibility_id,title,due_date,occurrence_key) VALUES(?,?,?,?,?)',[$r['role_id'],$r['id'],$r['title'],$date,$key]);$created+=(int)Db::one('SELECT changes() n')['n'];
  }});return $created;
 }
 public static function task(array $d):int{$role=record_id($d['role_id']??'');self::role($role,true);return Db::run('INSERT INTO work_tasks(role_id,title,priority,due_date) VALUES(?,?,?,?)',[$role,self::text($d['title']??''),int_range($d['priority']??2,1,3),valid_date($d['due_date']??'')]);}
 public static function status(int $id,string $status):void{if(!in_array($status,['open','doing','blocked','done'],true)||!Db::one('SELECT id FROM work_tasks WHERE id=?',[$id]))throw new InvalidArgumentException(t('Choose a valid work task status.'));Db::run("UPDATE work_tasks SET status=?,done_at=CASE WHEN ?='done' THEN COALESCE(done_at,?) ELSE NULL END WHERE id=?",[$status,$status,date('Y-m-d H:i:s'),$id]);}
 public static function start(int $role,?int $task=null,string $note='',?string $now=null):int{
  $r=self::role($role,true);$note=self::text($note,500,false);if($task&&!Db::one("SELECT id FROM work_tasks WHERE id=? AND role_id=? AND status<>'done'",[$task,$role]))throw new InvalidArgumentException(t('Choose an unfinished task from the selected role.'));
  return Db::tx(function()use($role,$task,$note,$now,$r){if(Db::one('SELECT id FROM role_time_logs WHERE ended_at IS NULL'))throw new InvalidArgumentException(t('Stop the running timer before switching roles.'));return Db::run('INSERT INTO role_time_logs(role_id,task_id,started_at,note,hourly_rate) VALUES(?,?,?,?,?)',[$role,$task,$now??date('Y-m-d H:i:s'),$note,$r['hourly_rate']]);});
 }
 public static function stop(?string $end=null):void{$r=Db::one('SELECT * FROM role_time_logs WHERE ended_at IS NULL');if(!$r)throw new InvalidArgumentException(t('No role timer is running.'));$end=$end?self::datetime($end):date('Y-m-d H:i:s');self::range($r['started_at'],$end);Db::run('UPDATE role_time_logs SET ended_at=? WHERE id=? AND ended_at IS NULL',[$end,$r['id']]);}
 private static function datetime(string $date):string{$date=str_replace('T',' ',$date);if(strlen($date)===16)$date.=':00';$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date);if(!$d||$d->format('Y-m-d H:i:s')!==$date)throw new InvalidArgumentException(t('Choose a valid date and time.'));return $date;}
 private static function range(string $start,string $end):void{$seconds=strtotime($end)-strtotime($start);if($seconds<0||$seconds>604800||strtotime($end)>time()+60)throw new InvalidArgumentException(t('Review the timer dates. An entry must be at most seven days and cannot end in the future.'));}
 public static function correct(int $id,array $d):void{$r=Db::one('SELECT * FROM role_time_logs WHERE id=? AND ended_at IS NOT NULL',[$id]);if(!$r)throw new InvalidArgumentException(t('Stop the timer before correcting its time.'));$start=self::datetime(self::text($d['started_at']??''));$end=self::datetime(self::text($d['ended_at']??''));self::range($start,$end);Db::run('UPDATE role_time_logs SET started_at=?,ended_at=?,note=? WHERE id=?',[$start,$end,self::text($d['note']??'',500,false),$id]);}
 public static function daily(array $d):void{$role=record_id($d['role_id']??'');self::role($role);$date=valid_date($d['date']??'');Db::run('INSERT INTO role_daily_logs(role_id,date,did,blocked,next) VALUES(?,?,?,?,?) ON CONFLICT(role_id,date) DO UPDATE SET did=excluded.did,blocked=excluded.blocked,next=excluded.next',[$role,$date,self::text($d['did']??'',5000,false),self::text($d['blocked']??'',5000,false),self::text($d['next']??'',5000,false)]);}
 public static function lesson(int $id):int{if(!module_enabled('Lessons'))throw new InvalidArgumentException(t('Enable Lessons to link a reflection.'));return Db::tx(function()use($id){$r=Db::one('SELECT * FROM role_daily_logs WHERE id=?',[$id]);if(!$r||trim($r['did'].$r['blocked'])==='')throw new InvalidArgumentException(t('Write what happened or what blocked you first.'));if($r['lesson_id'])return (int)$r['lesson_id'];$lesson=Db::run('INSERT INTO lessons(area,what_happened,root_cause,action) VALUES(?,?,?,?)',['Other',$r['did']?:$r['blocked'],$r['blocked'],$r['next']]);Db::run('UPDATE role_daily_logs SET lesson_id=? WHERE id=?',[$lesson,$id]);return $lesson;});}
 public static function summary(string $start,string $end):array{
  valid_date($start);valid_date($end);if($end<$start||strtotime($end)-strtotime($start)>366*86400)throw new InvalidArgumentException(t('Choose a reporting period of at most one year.'));
  $from=strtotime($start.' 00:00:00');$to=strtotime($end.' +1 day');$out=[];
  foreach(self::roles()as $r){$seconds=0;$value=0;foreach(Db::all('SELECT * FROM role_time_logs WHERE role_id=? AND started_at<? AND (ended_at IS NULL OR ended_at>?)',[$r['id'],date('Y-m-d H:i:s',$to),date('Y-m-d H:i:s',$from)])as $log){$n=max(0,min($to,strtotime($log['ended_at']??date('Y-m-d H:i:s')))-max($from,strtotime($log['started_at'])));$seconds+=$n;$value+=$n/3600*(float)$log['hourly_rate'];}
   $r['seconds']=$seconds;$r['estimated_income']=round($value,2);$r['completed']=(int)Db::one("SELECT COUNT(*) n FROM work_tasks WHERE role_id=? AND status='done' AND done_at>=? AND done_at<?",[$r['id'],$start.' 00:00:00',date('Y-m-d H:i:s',$to)])['n'];$out[]=$r;
  }return $out;
 }
 public static function income(int $role,string $start,string $end,string $date,mixed $amount):int{
  if(!module_enabled('Salary'))throw new InvalidArgumentException(t('Enable Money to record role income.'));$r=self::role($role);self::summary($start,$end);valid_date($date);
  return SalaryService::addIncome($date,$r['income_source']?:$r['name'],$amount,'freelance',function($income)use($role,$start,$end){if(Db::one('SELECT id FROM role_income_links WHERE role_id=? AND period_start=? AND period_end=? AND income_id IS NOT NULL',[$role,$start,$end]))throw new InvalidArgumentException(t('Income is already recorded for this role and period.'));Db::run('INSERT INTO role_income_links(role_id,period_start,period_end,income_id) VALUES(?,?,?,?) ON CONFLICT(role_id,period_start,period_end) DO UPDATE SET income_id=excluded.income_id',[$role,$start,$end,$income]);});
 }
}

<?php
declare(strict_types=1);
return function(Router $r):void{
 $r->get('/roles',function(){
  $date=valid_date(is_string($_GET['date']??null)?$_GET['date']:date('Y-m-d'));if($date===date('Y-m-d'))WorkRoleService::generate($date);
  $roles=WorkRoleService::roles();$selected=isset($_GET['role'])?record_id($_GET['role']):($roles[0]['id']??null);$role=$selected?WorkRoleService::role((int)$selected):null;
  $start=(new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');$end=date('Y-m-d',strtotime($start.' +6 days'));
  return View::render(__DIR__.'/views/index.php',['title'=>t('Work roles'),'date'=>$date,'roles'=>$roles,'role'=>$role,'view'=>($_GET['view']??'today')==='week'?'week':'today','start'=>$start,'end'=>$end,'summary'=>WorkRoleService::summary($start,$end),'running'=>Db::one('SELECT l.*,r.name role_name,t.title task_title FROM role_time_logs l JOIN work_roles r ON r.id=l.role_id LEFT JOIN work_tasks t ON t.id=l.task_id WHERE l.ended_at IS NULL'),'tasks'=>$role?Db::all("SELECT * FROM work_tasks WHERE role_id=? AND (due_date=? OR (due_date<? AND status<>'done')) ORDER BY due_date,priority,id",[$role['id'],$date,$date]):[],'daily'=>$role?Db::one('SELECT * FROM role_daily_logs WHERE role_id=? AND date=?',[$role['id'],$date]):null]);
 });
 $r->post('/roles/templates',function(){WorkRoleService::templates();return redirect('/roles',t('Default roles added. Your edited roles and records are preserved.'));});
 $r->post('/roles/save',function(){$id=WorkRoleService::saveRole($_POST,optional_id($_POST['id']??''));return redirect('/roles?role='.$id,t('Role saved.'));});
 $r->post('/roles/responsibilities',function(){WorkRoleService::responsibility($_POST,optional_id($_POST['id']??''));return redirect('/roles?role='.record_id($_POST['role_id']??''),t('Responsibility saved. Existing task history is preserved.'));});
 $r->post('/roles/tasks',function(){WorkRoleService::task($_POST);return redirect('/roles?role='.record_id($_POST['role_id']??''),t('Role task created.'));});
 $r->post('/roles/tasks/{id}/status',function($p){WorkRoleService::status(record_id($p['id']),text_input('status',20));return redirect('/roles?role='.record_id($_POST['role_id']??''),t('Work task updated.'));});
 $r->post('/roles/timer/start',function(){WorkRoleService::start(record_id($_POST['role_id']??''),optional_id($_POST['task_id']??''),text_input('note',500,false));return redirect('/roles?role='.record_id($_POST['role_id']??''),t('Role timer started.'));});
 $r->post('/roles/timer/stop',function(){WorkRoleService::stop(text_input('ended_at',30,false)?:null);return redirect('/roles',t('Role timer stopped. Review or correct the saved time below.'));});
 $r->post('/roles/time/{id}/correct',function($p){WorkRoleService::correct(record_id($p['id']),$_POST);return redirect('/roles?role='.record_id($_POST['role_id']??''),t('Time entry corrected.'));});
 $r->post('/roles/daily',function(){WorkRoleService::daily($_POST);return redirect('/roles?role='.record_id($_POST['role_id']??'').'&date='.valid_date($_POST['date']??''),t('Daily work log saved.'));});
 $r->post('/roles/daily/{id}/lesson',function($p){WorkRoleService::lesson(record_id($p['id']));return redirect('/roles',t('Reflection linked to Lessons.'));});
 $r->post('/roles/income',function(){WorkRoleService::income(record_id($_POST['role_id']??''),text_input('start',10),text_input('end',10),text_input('date',10),$_POST['amount']??'');return redirect('/roles?view=week',t('Received income recorded once through Money.'));});
};

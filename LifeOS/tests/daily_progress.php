<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function daily_check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function daily_reject(callable $fn,string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { daily_check(true,$label); return; } throw new RuntimeException($label); }
Db::init(':memory:'); $mods=Modules::discover(ROOT.'/app/Modules'); Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$mods)));
$today=date('Y-m-d'); $empty=DailyProgressService::day($today);
daily_check(!$empty['has_activity'] && $empty['tasks']===0 && $empty['expenses']===0.0,'Empty daily view invents no activity or balances');
daily_check(count(DailyProgressService::week($today))===7,'Daily history contains exactly seven real dates');
daily_reject(fn()=>DailyProgressService::date(date('Y-m-d',strtotime('+1 day'))),'Future progress is not presented as completed work');
daily_reject(fn()=>DailyProgressService::date(['date']),'Malformed review dates rejected');
daily_reject(fn()=>DailyProgressService::date('2026-02-30'),'Invalid calendar date rejected');
$task=PlannerService::saveTask(null,['title'=>'Actual action','due'=>$today]);
PlannerService::taskStatus($task,'done'); daily_check(DailyProgressService::day($today)['tasks']===1,'Actual task completion appears on its local day');
$stamp=Db::one('SELECT completed_at FROM tasks WHERE id=?',[$task])['completed_at']; PlannerService::taskStatus($task,'done'); daily_check(Db::one('SELECT completed_at FROM tasks WHERE id=?',[$task])['completed_at']===$stamp,'Repeated done requests do not change completion time');
PlannerService::taskStatus($task,'open'); daily_check(DailyProgressService::day($today)['tasks']===0 && Db::one('SELECT completed_at FROM tasks WHERE id=?',[$task])['completed_at']===null,'Reopened tasks are no longer counted as complete');
PlannerService::taskStatus($task,'done');
$goal=GrowthService::saveGoal(null,['title'=>'A real goal']); $step=GrowthService::saveStep($goal,null,['title'=>'One step']); GrowthService::stepStatus($goal,$step,'done'); daily_check(DailyProgressService::day($today)['goal_steps']===1,'Completed goal steps appear in daily progress');
GrowthService::stepStatus($goal,$step,'open'); daily_check(DailyProgressService::day($today)['goal_steps']===0,'Reopened goal steps leave completed total');
FreedomService::saveTime(null,['date'=>$today,'category'=>'rest','minutes'=>'30']);
$habit=PlannerService::saveHabit(null,['title'=>'A real habit']); PlannerService::checkHabit($habit,$today);
SalaryService::addExpense($today,1,1,'2.35','Actual expense');
$focus=FocusService::start('Actual study',25,'study',time()-600); FocusService::action($focus,'finish');
$day=DailyProgressService::day($today);
daily_check($day['personal_minutes']===30 && $day['habits']===1,'Personal time and habit check-ins use dated real entries');
daily_check($day['expenses']===2.35 && $day['expense_count']===1,'Spending uses dated expenses in exact stored amounts');
daily_check($day['focus_minutes']===10.0,'Study duration uses completed actual elapsed time');
daily_check($day['has_activity'],'Recorded activity enables daily summary');
SalaryService::addIncome($today,'Actual income','7.01'); daily_check(DailyProgressService::day($today)['income']===7.01 && DailyProgressService::day($today)['income_count']===1,'Daily income summary reflects recorded salary without changing spending');
$yesterday=date('Y-m-d',strtotime('-1 day')); daily_check(!DailyProgressService::day($yesterday)['has_activity'],'Today activity does not inflate yesterday');
$suggestions=DailyProgressService::suggestions(); daily_check(!$suggestions,'Completed tasks do not become suggested unfinished work');
PlannerService::saveTask(null,['title'=>'Due action','due'=>$today]); daily_check(DailyProgressService::suggestions()[0]['when']==='Today','Suggestions use saved actual due dates');
// Colombo day boundaries are based on UTC stored timestamps, not the laptop timezone.
$localStart=(new DateTimeImmutable($today,new DateTimeZone('Asia/Colombo')))->setTimezone(new DateTimeZone('UTC'));
Db::run('UPDATE focus_sessions SET completed_at=? WHERE id=?',[$localStart->modify('-1 second')->format('Y-m-d H:i:s'),$focus]);
daily_check(DailyProgressService::day($today)['focus_minutes']===0.0 && DailyProgressService::day($yesterday)['focus_minutes']===10.0,'UTC completion immediately before local midnight belongs to previous day');
Db::run('UPDATE focus_sessions SET completed_at=? WHERE id=?',[$localStart->format('Y-m-d H:i:s'),$focus]); daily_check(DailyProgressService::day($today)['focus_minutes']===10.0,'Local midnight is included in selected day');
Db::close();
// Old completed records keep an unknown date rather than fabricated upgrade-day progress.
Db::init(':memory:'); Db::exec(file_get_contents(ROOT.'/app/Core/migrations/001_core.sql')); Db::run("INSERT INTO tasks(title,status) VALUES ('Legacy action','done')"); Db::exec(file_get_contents(ROOT.'/app/Core/migrations/002_task_completion_dates.sql'));
daily_check(Db::one('SELECT completed_at FROM tasks')['completed_at']===null,'Legacy completion dates are not fabricated');
Db::close(); echo "$checks daily progress checks passed.\n";

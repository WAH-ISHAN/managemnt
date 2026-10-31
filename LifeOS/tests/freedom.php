<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function freedom_check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function freedom_rejects(callable $fn,string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { freedom_check(true,$label); return; } throw new RuntimeException($label); }
Db::init(':memory:'); $modules=Modules::discover(ROOT.'/app/Modules'); Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($module)=>$module['path'].'/migrations',$modules)));
$today=date('Y-m-d'); $empty=FreedomService::overview();
freedom_check($empty['weekly_work_hours']===null && $empty['monthly_essentials']===null && $empty['emergency_savings']===null && $empty['runway_months']===null && $empty['outstanding_debt']===null,'A fresh workspace does not invent work hours, costs, savings or runway');
freedom_check($empty['personal_minutes']===0 && $empty['time_entries']===[] && $empty['goals']===[] && $empty['appointments']===[],'Personal time and goals begin empty');
FreedomService::saveSettings(['weekly_work_hours'=>'37.5']); freedom_check(FreedomService::overview()['weekly_work_hours']===37.5,'Weekly work hours come only from user settings');
freedom_rejects(fn()=>FreedomService::saveSettings(['weekly_work_hours'=>'168.01']),'Weekly work hours cannot exceed the hours in a week');
freedom_check(FreedomService::overview()['weekly_work_hours']===37.5,'Invalid hours preserve the saved value');
FreedomService::saveSettings(['weekly_work_hours'=>'']); freedom_check(FreedomService::overview()['weekly_work_hours']===null,'Clearing work hours restores an unknown value');
$plan=['month'=>substr($today,0,7),'income'=>'100000','bills'=>'10000','loan_payments'=>'5000','living'=>'25000','savings_percent'=>'20','investment_percent'=>'10','emergency_account_id'=>'2'];
FinancePlanningService::savePlan($plan); Db::run('UPDATE accounts SET balance=120000 WHERE id=2');
$summary=FreedomService::overview(); freedom_check($summary['monthly_essentials']===40000.0 && $summary['emergency_savings']===120000.0 && $summary['runway_months']===3.0,'Runway uses the saved current plan and the linked real savings balance');
FinancePlanningService::savePlan(array_merge($plan,['emergency_account_id'=>''])); $unlinked=FreedomService::overview(); freedom_check($unlinked['emergency_savings']===null && $unlinked['runway_months']===null,'Unlinked balances are not assumed to be an emergency fund');
FinancePlanningService::savePlan(array_merge($plan,['bills'=>'0','loan_payments'=>'0','living'=>'0'])); freedom_check(FreedomService::overview()['runway_months']===null,'A zero essential-cost estimate does not fabricate infinite runway');
FinancePlanningService::savePlan($plan);
FinancePlanningService::addObligation(['title'=>'Recorded debt','kind'=>'loan','amount'=>'1000','outstanding'=>'5000','due_date'=>$today,'interval'=>'monthly','account_id'=>'1','category_id'=>'1']); freedom_check(FreedomService::overview()['outstanding_debt']===5000.0,'Debt comes from recorded outstanding loan balances');
$entry=FreedomService::saveTime(null,['date'=>$today,'category'=>'rest','minutes'=>'45','note'=>'  Read outside  ']);
freedom_check(FreedomService::record($entry)['note']==='Read outside','Personal time note is trimmed and saved');
FreedomService::saveTime(null,['date'=>$today,'category'=>'relationships','minutes'=>30]);
$week=FreedomService::overview(); freedom_check($week['personal_minutes']===75 && $week['time_totals']['rest']===45 && $week['time_totals']['relationships']===30 && count($week['time_entries'])===2,'Weekly totals report the minutes the user logged');
FreedomService::saveTime($entry,['date'=>$today,'category'=>'health','minutes'=>60,'note'=>'Walk']); $edited=FreedomService::overview(); freedom_check($edited['personal_minutes']===90 && $edited['time_totals']['rest']===0 && $edited['time_totals']['health']===60,'Editing personal time replaces its prior category and minutes');
freedom_rejects(fn()=>FreedomService::saveTime(null,['date'=>$today,'category'=>'health','minutes'=>1400]),'Daily totals cannot exceed 24 hours');
freedom_rejects(fn()=>FreedomService::saveTime(null,['date'=>date('Y-m-d',strtotime('+1 day')),'category'=>'rest','minutes'=>10]),'Future personal time is not represented as completed');
freedom_rejects(fn()=>FreedomService::saveTime(null,['date'=>$today,'category'=>'work','minutes'=>10]),'Unknown personal-time category rejected');
freedom_rejects(fn()=>FreedomService::saveTime(null,['date'=>$today,'category'=>'rest','minutes'=>0]),'A personal time entry requires positive minutes');
freedom_rejects(fn()=>FreedomService::saveTime(null,['date'=>$today,'category'=>'rest','minutes'=>10,'note'=>['not text']]),'Nested personal-time notes rejected cleanly');
$previous=(new DateTimeImmutable($week['week_start']))->modify('-1 day')->format('Y-m-d'); FreedomService::saveTime(null,['date'=>$previous,'category'=>'learning','minutes'=>120]); freedom_check(FreedomService::overview()['personal_minutes']===90,'Previous-week entries do not inflate current-week personal time');
freedom_check(count(FreedomService::overview()['recent_time_entries'])===3 && count(FreedomService::overview()['time_entries'])===2,'Historical logs remain available without inflating current-week totals');
FreedomService::deleteTime($entry); freedom_check(FreedomService::overview()['personal_minutes']===30,'Deleting a time entry updates the weekly total');
freedom_rejects(fn()=>FreedomService::record($entry),'Deleted entries cannot be edited silently');
$goal=GrowthService::saveGoal(null,['title'=>'Make time for family']); freedom_check((int)FreedomService::overview()['goals'][0]['id']===$goal,'Freedom overview includes actual active goals');
freedom_check(in_array('freedom_time',SettingsService::tables(),true),'Personal time can be exported with other user records');
// Freedom remains usable when the optional money, goals and planner schemas are absent.
Db::close(); Db::init(':memory:'); Db::exec(file_get_contents(ROOT.'/app/Core/migrations/001_core.sql')); Db::exec(file_get_contents(ROOT.'/app/Modules/Freedom/migrations/001_create.sql'));
$standalone=FreedomService::overview(); freedom_check($standalone['plan']===null && $standalone['outstanding_debt']===null && $standalone['goals']===[] && $standalone['appointments']===[],'Freedom safely omits unavailable module data');
freedom_check(OnboardingService::needed(),'An unused standalone workspace needs onboarding');
FreedomService::saveTime(null,['date'=>$today,'category'=>'rest','minutes'=>15]); freedom_check(!OnboardingService::needed(),'Existing personal-time records prevent an unexpected onboarding redirect');
Db::close(); echo "$checks freedom checks passed.\n";

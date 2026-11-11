<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function month_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
$file=sys_get_temp_dir().'/lifeos-month-'.bin2hex(random_bytes(8)).'.db';
try {
    Db::init($file); $modules=Modules::discover(ROOT.'/app/Modules'); Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    PlannerService::saveTask(null,['title'=>'Math paper','due'=>'2099-06-15','category'=>'exam']);
    PlannerService::saveAppointment(null,['title'=>'Domain renewal','kind'=>'renewal','all_day'=>1,'starts_on'=>'2099-06-15','ends_on'=>'2099-06-15']);
    PlannerService::saveAppointment(null,['title'=>'Conference','kind'=>'event','all_day'=>1,'starts_on'=>'2099-05-31','ends_on'=>'2099-06-02']);
    $month=PlannerService::calendarMonth('2099-06-15')['items'];
    $labels=array_map(fn($item)=>$item['kind'].':'.$item['title'],$month['2099-06-15']);
    month_check($labels===['Exam:Math paper','Renewal:Domain renewal'],'Exam and renewal appear with their correct type.');
    month_check(count($month['2099-06-01'])===1 && count($month['2099-06-02'])===1 && !isset($month['2099-06-03']),'Spanning events respect exclusive ends.');
    try { PlannerService::saveAppointment(null,['title'=>'Bad','kind'=>'invalid','all_day'=>1,'starts_on'=>'2099-06-15','ends_on'=>'2099-06-15']); throw new RuntimeException('Invalid kind accepted.'); } catch (InvalidArgumentException) { month_check(true,'Invalid kind is rejected.'); }
    $spill=PlannerService::saveTask(null,['title'=>'Next month task','due'=>'2099-07-02']);
    month_check(isset(PlannerService::calendarMonth('2099-06-15')['items']['2099-07-02']),'Entries on displayed adjacent-month dates are included.');
    PlannerService::taskStatus($spill,'done');
    month_check(!isset(PlannerService::calendarMonth('2099-06-15')['items']['2099-07-02']),'Completed tasks leave the schedule.');
    $cancel=PlannerService::saveAppointment(null,['title'=>'Cancelled event','all_day'=>1,'starts_on'=>'2099-06-22','ends_on'=>'2099-06-22','status'=>'cancelled']);
    month_check(!isset(PlannerService::calendarMonth('2099-06-15')['items']['2099-06-22']),'Cancelled events leave the schedule.');
    month_check(str_contains($month['2099-06-15'][1]['url'],'appointment='),'Calendar opens a specific appointment regardless of recent-list limits.');
    month_check(PlannerService::requireRow('planner_appointments',$cancel)['kind']==='event','Existing integrations default to the event type.');
    $beforeTasks=(int)Db::one('SELECT COUNT(*) n FROM tasks')['n']; $beforeAppointments=(int)Db::one('SELECT COUNT(*) n FROM planner_appointments')['n'];
    $module=Db::run('INSERT INTO uni_modules(name,credits) VALUES (?,3)',['University source exam']);
    $exam=Db::run('INSERT INTO exams(module_id,date,venue) VALUES (?,?,?)',[$module,'2099-06-20','QA venue']);
    Db::run('INSERT INTO exams(module_id,date) VALUES (?,?)',[$module,'2099-09-01']);
    $entries=PlannerService::calendarMonth('2099-06-15')['items'];
    month_check(($entries['2099-06-20'] ?? [])===[['title'=>'University source exam','kind'=>'Exam','url'=>'/uni?view=exams']],'University exam appears with its module name and working source link.');
    month_check(!isset($entries['2099-09-01']),'University exams outside the displayed calendar range are excluded.');
    Db::run('UPDATE uni_modules SET name=? WHERE id=?',['Renamed source exam',$module]);
    Db::run('UPDATE exams SET date=? WHERE id=?',['2099-06-21',$exam]);
    $entries=PlannerService::calendarMonth('2099-06-15')['items'];
    month_check(!isset($entries['2099-06-20']) && ($entries['2099-06-21'][0]['title'] ?? '')==='Renamed source exam','Source exam edits immediately change its calendar title and date.');
    PersonaService::apply('uni');
    Db::run("UPDATE modules SET enabled=0 WHERE profile_id='default' AND module_id='Uni'");
    month_check(!isset(PlannerService::calendarMonth('2099-06-15')['items']['2099-06-21']),'Disabled University module does not expose exam records in Planner.');
    PersonaService::apply('employee');
    month_check(!isset(PlannerService::calendarMonth('2099-06-15')['items']['2099-06-21']),'A profile without the University role does not expose saved exam records.');
    PersonaService::apply('uni');
    month_check(isset(PlannerService::calendarMonth('2099-06-15')['items']['2099-06-21']),'Re-enabling the University role restores its calendar exams.');
    Db::run('DELETE FROM exams WHERE id=?',[$exam]);
    month_check(!isset(PlannerService::calendarMonth('2099-06-15')['items']['2099-06-21']),'Deleting the source exam immediately removes its calendar entry.');
    month_check((int)Db::one('SELECT COUNT(*) n FROM tasks')['n']===$beforeTasks && (int)Db::one('SELECT COUNT(*) n FROM planner_appointments')['n']===$beforeAppointments,'Calendar reads do not mirror university exams into tasks or appointments.');
    echo "Planner month calendar: $checks checks passed.\n";
} finally { Db::close(); @unlink($file); }

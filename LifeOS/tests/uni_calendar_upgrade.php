<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function ucu_check(bool $ok,string $label):void { global $checks;if(!$ok)throw new RuntimeException($label);$checks++; }
function ucu_records():array {
    $snapshot=[];
    foreach(Db::all("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name<>'migrations' ORDER BY name") as $table) {
        $name=$table['name'];
        $snapshot[$name]=['schema'=>$table['sql'],'rows'=>Db::all('SELECT * FROM "'.str_replace('"','""',$name).'" ORDER BY rowid')];
    }
    return $snapshot;
}
$temporary=sys_get_temp_dir().'/lifeos-calendar-upgrade-'.bin2hex(random_bytes(8));
mkdir($temporary,0700,true);$copiedFiles=[];$copiedDirs=[];
try {
    $modules=Modules::discover(ROOT.'/app/Modules');
    $currentDirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules));
    $oldDirs=[];$excluded=[];$newMigrationKeys=['Uni/002_calendar.sql','Uni/003_calendar_notifications.sql','Insights/002_notification_reads.sql','Insights/003_desktop_panel.sql'];
    foreach($currentDirs as $source) {
        $module=basename(dirname($source));$target=$temporary.'/'.$module.'/migrations';
        mkdir($target,0700,true);$copiedDirs[]=$target;$copiedDirs[]=dirname($target);$oldDirs[]=$target;
        foreach(glob($source.'/*.sql')?:[] as $file) {
            $key=$module.'/'.basename($file);if(in_array($key,$newMigrationKeys,true)){$excluded[]=$key;continue;}
            $copy=$target.'/'.basename($file);if(!copy($file,$copy))throw new RuntimeException('Could not create isolated old migration fixture.');$copiedFiles[]=$copy;
        }
    }
    sort($excluded);$expectedExcluded=$newMigrationKeys;sort($expectedExcluded);
    ucu_check($excluded===$expectedExcluded,'Old-schema fixture excludes exactly the three new calendar and notification migrations');
    $database=$temporary.'/lifeos.db';Db::init($database);Migrator::run($oldDirs);
    ucu_check(Db::one("SELECT name FROM sqlite_master WHERE name='uni_calendar_events'")===null,'Pre-upgrade database has no calendar table');
    ucu_check(Db::one("SELECT name FROM sqlite_master WHERE name IN ('uni_calendar_notifications','notification_reads','desktop_panel_notes')")===null,'Pre-upgrade database has none of the new notification or panel tables');
    $oldMigrations=Db::all('SELECT * FROM migrations ORDER BY file');
    ucu_check(count($oldMigrations)===array_sum(array_map(fn($d)=>count(glob($d.'/*.sql')?:[]),$currentDirs))-count($newMigrationKeys),'All other current migrations are present with original keys/checksums');
    Db::set('name','Upgrade preservation QA owner');Db::set('institution','Disposable university');Db::set('upgrade_marker','Keep this exact value');
    $task=PlannerService::saveTask(null,['title'=>'Keep my existing study task','due'=>'2026-11-07']);
    $appointment=PlannerService::saveAppointment(null,['title'=>'Existing dentist visit','starts_at'=>'2026-11-08 09:15','ends_at'=>'2026-11-08 10:00','notes'=>'Preserve my notes','location'=>'Existing room','reminder_minutes'=>'30']);
    $module=Db::run('INSERT INTO uni_modules(code,name,credits,semester,grade) VALUES (?,?,?,?,?)',['DB101','Existing databases module',3,'Semester 1','A-']);
    Db::run('INSERT INTO assignments(module_id,title,due_date,status,weight,marks) VALUES (?,?,?,?,?,?)',[$module,'Existing assignment','2026-11-10','doing',25,73]);
    Db::run('INSERT INTO exams(module_id,date,venue,marks) VALUES (?,?,?,?)',[$module,'2026-11-20','Existing exam room',81]);
    Db::run('INSERT INTO timetable(module_id,day,start_time,end_time,venue) VALUES (?,?,?,?,?)',[$module,2,'08:30','10:30','Existing class']);
    SalaryService::addIncome('2026-10-25','Existing salary','1200.53');
    SalaryService::addExpense('2026-10-26',1,2,'200.10','Existing expense');
    $before=ucu_records();
    Db::close();Db::init($database);
    ucu_check(ucu_records()===$before,'Pre-upgrade user records persist after closing and reopening the fixture database');
    Migrator::run($currentDirs);
    ucu_check(Db::one("SELECT name FROM sqlite_master WHERE name='uni_calendar_events'")!==null,'New migration creates the university calendar table');
    $after=ucu_records();$calendar=$after['uni_calendar_events'];unset($after['uni_calendar_events']);
    foreach(['uni_calendar_notifications','notification_reads','desktop_panel_notes'] as $newTable){ucu_check(isset($after[$newTable])&&$after[$newTable]['rows']===[],'Upgrade creates empty '.$newTable.' without fabricated history');unset($after[$newTable]);}
    ucu_check($after===$before,'Every pre-existing user table retains its exact schema and rows through upgrade');
    ucu_check($calendar['rows']===[],'Upgrade does not fabricate imported lectures');
    $newMigrations=Db::all('SELECT * FROM migrations ORDER BY file');
    ucu_check(count($newMigrations)===count($oldMigrations)+count($newMigrationKeys),'Upgrade applies exactly the three intended migrations');
    foreach($oldMigrations as $migration) {
        $row=Db::one('SELECT * FROM migrations WHERE file=?',[$migration['file']]);
        if($row!==$migration)throw new RuntimeException('Upgrade rewrote original migration history: '.$migration['file']);
    }
    ucu_check(true,'Original migration keys, checksums and applied timestamps remain unchanged');
    foreach($newMigrationKeys as $key){[$module,$file]=explode('/',$key,2);$new=Db::one('SELECT checksum FROM migrations WHERE file=?',[$key]);ucu_check($new!==null&&$new['checksum']===hash_file('sha256',ROOT.'/app/Modules/'.$module.'/migrations/'.$file),'New migration uses its actual source checksum: '.$key);}
    $once=ucu_records();Migrator::run($currentDirs);
    ucu_check(ucu_records()===$once&&Db::all('SELECT * FROM migrations ORDER BY file')===$newMigrations,'Repeated startup migration is idempotent');
    $batch='1f8d4470-5a5a-4f90-98df-af06b2a73422';
    UniCalendarService::configure('https://aca.mynibm.com/dashboard?batch='.$batch);
    $result=UniCalendarService::import($batch,'2026-11-01','2026-11-30',[[
        'lectureId'=>'cc7f12ba-6eef-4bc0-9432-99f9875eca01','batchId'=>$batch,
        'moduleName'=>'Imported lecture after upgrade','lectureType'=>'LECTURE_PHYSICAL','status'=>'APPROVED',
        'startAt'=>'2026-11-08T03:30:00Z','endAt'=>'2026-11-08T05:30:00Z'
    ]]);
    ucu_check($result['created']===1,'Upgraded database accepts its first legitimate calendar sync');
    ucu_check((int)Db::one('SELECT COUNT(*) n FROM uni_calendar_notifications')['n']===1,'First sync after upgrade creates exactly one calendar notification');
    ucu_check(Db::one('SELECT * FROM planner_appointments WHERE id=?',[$appointment])===$before['planner_appointments']['rows'][0],'Imported lecture preserves original manual appointment exactly');
    ucu_check(Db::one('SELECT * FROM tasks WHERE id=?',[$task])===$before['tasks']['rows'][0],'Imported lecture preserves original task exactly');
    foreach(['uni_modules','assignments','exams','timetable','accounts','income','expenses','allocations'] as $table) {
        if(!isset($before[$table]))continue;
        ucu_check(Db::all('SELECT * FROM "'.$table.'" ORDER BY rowid')===$before[$table]['rows'],'First sync preserves existing '.$table.' rows');
    }
    ucu_check(Db::setting('upgrade_marker')==='Keep this exact value','Existing user settings survive upgrade and sync');
    Db::close();Db::init($database);Migrator::run($currentDirs);
    ucu_check((int)Db::one('SELECT COUNT(*) n FROM uni_calendar_events')['n']===1,'Imported lecture persists after upgraded app restarts');
    ucu_check(Db::one('PRAGMA integrity_check')['integrity_check']==='ok'&&Db::all('PRAGMA foreign_key_check')===[],'Upgraded database remains valid with all references intact');
    echo "$checks University calendar upgrade preservation checks passed.\n";
} finally {
    Db::close();
    foreach([$temporary.'/lifeos.db',$temporary.'/lifeos.db-wal',$temporary.'/lifeos.db-shm',...$copiedFiles] as $file)if(is_file($file))unlink($file);
    usort($copiedDirs,fn($a,$b)=>strlen($b)<=>strlen($a));foreach($copiedDirs as $directory)if(is_dir($directory))rmdir($directory);
    if(is_dir($temporary))rmdir($temporary);
}

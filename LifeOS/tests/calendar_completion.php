<?php
declare(strict_types=1);
$temporary=sys_get_temp_dir().'/lifeos-calendar-completion-'.bin2hex(random_bytes(6)); mkdir($temporary,0700,true); define('DATA',$temporary);
require __DIR__.'/bootstrap.php';
$checks=0;
function calendar_check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function calendar_reject(callable $fn,string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { calendar_check(true,$label); return; } throw new RuntimeException($label); }
function calendar_event(string $id,string $version='v1'): array { return ['remote_id'=>$id,'version'=>$version,'title'=>'Remote calendar '.$id,'starts_at'=>'2099-06-12 09:00','ends_at'=>'2099-06-12 10:00']; }
try {
    Db::init(DATA.'/lifeos.db'); $modules=Modules::discover(ROOT.'/app/Modules'); $dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)); Migrator::run($dirs); Migrator::run($dirs);
    calendar_check(!ConnectivityService::config()['calendar_delete'],'Existing publishing preferences do not enable remote deletion');
    $allDay=PlannerService::saveAppointment(null,['title'=>'Two-day leave','all_day'=>1,'starts_on'=>'2099-06-12','ends_on'=>'2099-06-13']);
    $row=PlannerService::requireRow('planner_appointments',$allDay);
    calendar_check($row['all_day']===1 && $row['starts_at']==='2099-06-12 00:00' && $row['ends_at']==='2099-06-14 00:00','Inclusive form dates become an exclusive midnight range');
    calendar_check(count(PlannerService::appointmentsOn('2099-06-13'))===1 && PlannerService::appointmentsOn('2099-06-14')===[],'Multi-day event covers its last day without leaking into the next');
    calendar_reject(fn()=>PlannerService::saveAppointment(null,['title'=>'Invalid','all_day'=>1,'starts_on'=>'2099-06-13','ends_on'=>'2099-06-12']),'Reverse all-day form range is rejected');
    calendar_reject(fn()=>PlannerService::saveAppointment(null,['title'=>'Invalid','all_day'=>1,'starts_at'=>'2099-06-12 01:00','ends_at'=>'2099-06-13 00:00']),'Non-midnight all-day adapter boundary is rejected');
    calendar_reject(fn()=>PlannerService::saveAppointment(null,['title'=>'Invalid','all_day'=>'yes','starts_at'=>'2099-06-12 09:00','ends_at'=>'2099-06-12 10:00']),'Malformed all-day flags are rejected');
    $ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:date-only\r\nDTSTART;VALUE=DATE:20990620\r\nSUMMARY:Day off\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $preview=ConnectivityIcs::preview($ics)[0];
    calendar_check($preview['all_day']===1 && $preview['starts_at']==='2099-06-20 00:00' && $preview['ends_at']==='2099-06-21 00:00','RFC5545 date-only event defaults to one calendar day');
    ConnectivityIcs::import($ics); ConnectivityIcs::import($ics);
    calendar_check((int)Db::one("SELECT COUNT(*) n FROM planner_appointments WHERE title='Day off'")['n']===1,'All-day import preserves UID deduplication');
    $roundTrip=ConnectivityIcs::preview(ConnectivityIcs::export());
    $leave=array_values(array_filter($roundTrip,fn($r)=>$r['title']==='Two-day leave'))[0];
    calendar_check($leave['all_day']===1 && $leave['ends_at']==='2099-06-14 00:00','All-day ICS export/import retains exclusive end dates');
    calendar_reject(fn()=>ConnectivityIcs::preview(str_replace('20990620','20990230',$ics)),'Invalid calendar dates do not normalize silently');
    calendar_reject(fn()=>ConnectivityIcs::preview(str_replace('SUMMARY:','DTEND:20990621T010000Z\r\nSUMMARY:',$ics)),'Mixed timed/all-day event boundaries are rejected');
    $calls=[]; $writer=function(array $event,?array $link) use (&$calls): array { $calls[]=['event'=>$event,'link'=>$link]; return ['remote_id'=>$link['remote_id'] ?? 'created-'.count($calls),'version'=>'written-v2','deleted'=>$event['status']==='cancelled']; };
    $noWrite=fn()=>throw new RuntimeException('Unexpected provider write');
    $event=calendar_event('all-day')+['all_day'=>1]; $event['starts_at']='2099-06-22 00:00'; $event['ends_at']='2099-06-24 00:00';
    ConnectivityCalendar::reconcile('import-only',[$event],false,$noWrite);
    $linked=Db::one("SELECT a.* FROM planner_appointments a JOIN connectivity_events e ON e.appointment_id=a.id WHERE e.provider='import-only'");
    calendar_check($linked['all_day']===1 && $linked['ends_at']==='2099-06-24 00:00','Provider all-day dates are retained in appointments');
    $legacy=calendar_event('legacy'); ConnectivityCalendar::reconcile('legacy-provider',[$legacy],false,$noWrite);
    $legacy['version']='v2'; $legacy['title']='Updated remote';
    calendar_check(ConnectivityCalendar::reconcile('legacy-provider',[$legacy],false,$noWrite)['updated']===1,'Timed-only baseline hashes remain compatible');
    $cancel=calendar_event('cancel'); ConnectivityCalendar::reconcile('cancel-provider',[$cancel],false,$noWrite);
    $link=Db::one("SELECT * FROM connectivity_events WHERE provider='cancel-provider'");
    Db::run("UPDATE planner_appointments SET status='cancelled' WHERE id=?",[$link['appointment_id']]);
    calendar_check(ConnectivityCalendar::reconcile('cancel-provider',[$cancel],true,$noWrite)['deleted']===0,'Previous new/edit publishing consent never sends cancellations');
    calendar_check(ConnectivityCalendar::reconcile('cancel-provider',[$cancel],true,$writer,true)['deleted']===1,'Separate deletion opt-in publishes a local cancellation');
    calendar_check($calls[0]['link']['remote_version']==='v1' && $calls[0]['event']['status']==='cancelled','Cancellation retains the last seen remote version');
    calendar_check(ConnectivityCalendar::reconcile('cancel-provider',[],true,$noWrite,true)['deleted']===0,'Completed cancellation is not sent twice');
    $deleted=calendar_event('deleted'); ConnectivityCalendar::reconcile('delete-provider',[$deleted],false,$noWrite);
    $link=Db::one("SELECT * FROM connectivity_events WHERE provider='delete-provider'");
    PlannerService::remove('planner_appointments',(int)$link['appointment_id']);
    $tombstone=Db::one('SELECT * FROM connectivity_events WHERE id=?',[$link['id']]);
    calendar_check($tombstone['local_deleted']===1 && $tombstone['appointment_id']===null,'Deletion retains its provider identity/version after the FK is cleared');
    calendar_check(ConnectivityCalendar::reconcile('delete-provider',[],true,$writer,true)['deleted']===1,'Tombstone propagates even outside the fetched date window');
    calendar_check(ConnectivityCalendar::reconcile('delete-provider',[$deleted],true,$noWrite,true)['deleted']===0,'Stale provider listing cannot replay a completed deletion');
    $conflict=calendar_event('changed'); ConnectivityCalendar::reconcile('conflict-provider',[$conflict],false,$noWrite);
    $link=Db::one("SELECT * FROM connectivity_events WHERE provider='conflict-provider'"); PlannerService::remove('planner_appointments',(int)$link['appointment_id']); $conflict['version']='changed-remotely';
    calendar_check(ConnectivityCalendar::reconcile('conflict-provider',[$conflict],true,$noWrite,true)['conflicts']===1,'A changed remote record is not removed by a stale local deletion');
    calendar_check(ConnectivityCalendar::reconcile('conflict-provider',[],true,fn()=>['conflict'=>true],true)['conflicts']===1,'Conditional provider rejection remains a conflict');
    calendar_check(Db::one('SELECT remote_deleted FROM connectivity_events WHERE id=?',[$link['id']])['remote_deleted']===0,'Failed deletion stays retryable without losing its tombstone');
    calendar_check(ConnectivityCalendar::reconcile('conflict-provider',[],false,$noWrite,true)['deleted']===0,'Deletion opt-in alone cannot bypass publishing being disabled');
    calendar_check(Db::all('PRAGMA foreign_key_check')===[] && Db::one('PRAGMA integrity_check')['integrity_check']==='ok','Calendar migrations and sync preserve database integrity');
    echo "Calendar completion: $checks checks passed.\n";
} finally { Db::close(); foreach (glob($temporary.'/*') ?: [] as $file) if (is_file($file)) unlink($file); rmdir($temporary); }

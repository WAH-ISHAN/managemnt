<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function uc_check(bool $ok,string $label):void { global $checks;if(!$ok)throw new RuntimeException($label);$checks++; }
function uc_reject(callable $fn,string $label):void { try{$fn();}catch(InvalidArgumentException){uc_check(true,$label);return;}throw new RuntimeException($label); }
function uc_snapshot():array { return [Db::all('SELECT * FROM uni_calendar_events ORDER BY id'),Db::all('SELECT * FROM planner_appointments ORDER BY id'),Db::all('SELECT * FROM uni_calendar_notifications ORDER BY id'),Db::all('SELECT * FROM notification_reads ORDER BY key'),UniCalendarService::config()]; }
function uc_notification_count():int { return (int)Db::one('SELECT COUNT(*) n FROM uni_calendar_notifications')['n']; }
function uc_calendar_alerts():array { return array_values(array_filter(InsightsService::alerts(),fn($a)=>($a['kind']??'')==='calendar')); }
function uc_event(string $id,string $start='2026-10-30T03:30:00.000Z',string $end='2026-10-30T05:30:00Z',string $status='APPROVED'):array {
    return ['lectureId'=>$id,'moduleName'=>'Database Systems','moduleId'=>'b1c21ce5-f271-432a-b65a-d8bfa96dfdee','lectureType'=>'LECTURE_PHYSICAL','startAt'=>$start,'endAt'=>$end,'status'=>$status,'facilityName'=>'Room A','batchName'=>'Disposable QA batch','internalLecturerName'=>'Test lecturer'];
}
function uc_appointment(string $remote):?array { return Db::one('SELECT p.* FROM planner_appointments p JOIN uni_calendar_events e ON e.appointment_id=p.id WHERE e.remote_id=?',[$remote]); }
Db::init(':memory:');
try {
    $modules=Modules::discover(ROOT.'/app/Modules');Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    $batch='1f8d4470-5a5a-4f90-98df-af06b2a73422';$otherBatch='325cbb66-7bee-45b9-bd94-f21d1defc01d';
    $first='cc7f12ba-6eef-4bc0-9432-99f9875eca01';$second='cc7f12ba-6eef-4bc0-9432-99f9875eca02';$third='cc7f12ba-6eef-4bc0-9432-99f9875eca03';
    $url='https://aca.mynibm.com/dashboard?view=month&year=2026&month=9&batch='.$batch;
    uc_check(!UniCalendarService::config()['enabled'],'Fresh installation has no enabled portal connection');
    uc_check(Db::all('SELECT * FROM uni_calendar_events')===[],'Fresh installation contains no invented lectures');
    uc_check(uc_notification_count()===0&&uc_calendar_alerts()===[],'Fresh installation contains no invented calendar notifications');
    foreach(['http://aca.mynibm.com/dashboard?batch='.$batch,'https://evil.example/dashboard?batch='.$batch,'https://aca.mynibm.com.evil.example/dashboard?batch='.$batch,'https://user:password@aca.mynibm.com/dashboard?batch='.$batch,'https://aca.mynibm.com/not-dashboard?batch='.$batch,'https://aca.mynibm.com/dashboard','https://aca.mynibm.com/dashboard?batch=bad','https://aca.mynibm.com/dashboard?batch='.$batch.'&batch='.$otherBatch] as $invalid) {
        uc_reject(fn()=>UniCalendarService::configure($invalid),'Malformed or untrusted portal URL rejected: '.$invalid);
    }
    UniCalendarService::configure($url);$config=UniCalendarService::config();
    uc_check($config['enabled']&&$config['batchId']===$batch,'Selected university batch saved and enabled');
    $manual=PlannerService::saveAppointment(null,['title'=>'Personal dentist','starts_at'=>'2026-10-30 09:00','ends_at'=>'2026-10-30 10:00']);$manualBefore=PlannerService::requireRow('planner_appointments',$manual);
    $result=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[uc_event($first)]);
    uc_check($result['created']===1,'Confirmed lecture creates one planner appointment');
    uc_check(uc_notification_count()===1,'First confirmed lecture sync creates one summary notification');
    $notification=Db::one('SELECT * FROM uni_calendar_notifications ORDER BY id DESC LIMIT 1');$alert=uc_calendar_alerts()[0]??[];
    uc_check(($alert['id']??'')===hash('sha256','nibm-sync:'.$notification['id'])&&($alert['created_at']??'')===$notification['created_at']&&($alert['read']??null)===false,'Calendar alert has stable event identity, creation time and unread state');
    $saved=uc_appointment($first);$appointment=(int)$saved['id'];
    uc_check($saved['starts_at']==='2026-10-30 09:00'&&$saved['ends_at']==='2026-10-30 11:00','UTC timestamps convert to exact Colombo date and time');
    uc_check($saved['status']==='scheduled'&&$saved['location']==='Room A','Active lecture and venue stored for planner/reminders');
    uc_check((bool)UniCalendarService::config()['lastSync'],'Successful snapshot records a sync time');
    $repeated=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[uc_event($first)]);
    uc_check((int)uc_appointment($first)['id']===$appointment&&count(Db::all('SELECT * FROM uni_calendar_events'))===1,'Repeated snapshot keeps same appointment without duplicates');
    uc_check($repeated['created']===0&&$repeated['updated']===0&&$repeated['cancelled']===0&&uc_notification_count()===1,'Unchanged periodic sync reports no changes and sends no duplicate notification');
    $metadataOnly=uc_event($first);$metadataOnly['updatedAt']='2026-10-30T08:00:00Z';$metadataResult=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$metadataOnly]);
    uc_check($metadataResult['updated']===0&&uc_notification_count()===1,'Remote metadata timestamp changes alone do not create update notifications');
    $changed=uc_event($first,'2026-10-31T04:00:00+05:30','2026-10-31T06:00:00+05:30','RESCHEDULED_APPROVED');$changed['moduleName']='Updated title';$changed['facilityName']='Room B';
    $changedResult=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$changed]);$saved=uc_appointment($first);
    uc_check((int)$saved['id']===$appointment&&$saved['starts_at']==='2026-10-31 04:00'&&$saved['ends_at']==='2026-10-31 06:00'&&$saved['location']==='Room B','Changed dates and venue update existing identity');
    uc_check(str_contains($saved['title'],'Updated title'),'Updated module title reaches linked appointment');
    uc_check($changedResult['updated']===1&&uc_notification_count()===2,'Changed lecture time, title and venue produce exactly one update notification');
    foreach([['moduleName'=>'Title-only change'],['facilityName'=>'Room C'],['internalLecturerName'=>'New lecturer'],['startAt'=>'2026-10-31T04:30:00+05:30','endAt'=>'2026-10-31T06:30:00+05:30']] as $edit){
        $notificationCount=uc_notification_count();$changed=array_replace($changed,$edit);$editResult=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$changed]);
        uc_check($editResult['updated']===1&&uc_notification_count()===$notificationCount+1,'A real title, venue, lecturer-note or time-only change produces one update notification');
    }
    $before=uc_snapshot();
    $badEvents=[
        array_replace($changed,['lectureId'=>'not-a-uuid']),
        array_replace($changed,['startAt'=>'2026-02-30T04:00:00Z']),
        array_replace($changed,['startAt'=>'2026-10-31T04:00:00']),
        array_replace($changed,['startAt'=>'2026-10-31T07:00:00+05:30']),
        array_replace($changed,['status'=>'UNKNOWN_UPSTREAM_STATUS']),
        array_replace($changed,['batchId'=>$otherBatch]),
        array_replace($changed,['startAt'=>'2026-11-01T04:00:00Z','endAt'=>'2026-11-01T06:00:00Z']),
    ];
    $withoutStatus=$changed;unset($withoutStatus['status']);$badEvents[]=$withoutStatus;
    foreach($badEvents as $bad) {
        uc_reject(fn()=>UniCalendarService::import($batch,'2026-10-01','2026-10-31',[uc_event($second),$bad]),'Malformed snapshot rejected before any write');
        uc_check(uc_snapshot()===$before,'Rejected snapshot preserves appointments, provider records and sync state atomically');
    }
    uc_reject(fn()=>UniCalendarService::import($otherBatch,'2026-10-01','2026-10-31',[$changed]),'Unselected batch cannot import');
    uc_reject(fn()=>UniCalendarService::import($batch,'2026-10-31','2026-10-01',[]),'Inverted snapshot window rejected');
    uc_reject(fn()=>UniCalendarService::import($batch,'2026-02-30','2026-10-31',[]),'Invalid snapshot date rejected');
    uc_reject(fn()=>UniCalendarService::import($batch,'2026-01-01','2027-02-05',[]),'Oversized snapshot window rejected');
    uc_reject(fn()=>UniCalendarService::import($batch,'2026-10-01','2026-10-31',array_fill(0,2001,$changed)),'Oversized response rejected');
    uc_reject(fn()=>UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$changed,$changed]),'Duplicate remote IDs cannot overwrite each other in a snapshot');
    uc_reject(fn()=>UniCalendarService::import($batch,'2026-10-01','2026-10-31',['lectures'=>[$changed]]),'Wrong response container cannot be mistaken for a complete snapshot');
    uc_check(uc_snapshot()===$before,'Rejected request boundaries leave all state intact');
    UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$changed,uc_event($second)]);
    UniCalendarService::import($batch,'2026-11-01','2026-11-30',[uc_event($third,'2026-11-15T03:30:00Z','2026-11-15T05:30:00Z')]);
    UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$changed]);
    uc_check(uc_appointment($second)['status']==='cancelled','Missing lecture in successful complete range is cancelled');
    uc_check(uc_appointment($third)['status']==='scheduled','Snapshot preserves imported appointments outside its range');
    uc_check(PlannerService::requireRow('planner_appointments',$manual)===$manualBefore,'Manual appointment untouched by synchronization and cancellation');
    foreach(['CANCELLED','RESCHEDULED_CANCELLED','PENDING_APPROVAL','RESCHEDULED_PENDING_APPROVAL','REJECTED','RESCHEDULED_REJECTED','RESCHEDULED'] as $status) {
        UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$changed]);
        $notificationCount=uc_notification_count();$cancelled=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[array_replace($changed,['status'=>$status])]);
        uc_check(uc_appointment($first)['status']==='cancelled','Unconfirmed or cancelled lecture does not remain a reminder: '.$status);
        uc_check($cancelled['cancelled']===1&&uc_notification_count()===$notificationCount+1,'Actual cancellation sends exactly one summary notification: '.$status);
        $unchanged=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[array_replace($changed,['status'=>$status])]);
        uc_check($unchanged['created']===0&&$unchanged['updated']===0&&$unchanged['cancelled']===0&&uc_notification_count()===$notificationCount+1,'Repeated cancelled snapshot does not send another notification: '.$status);
    }
    $unconfirmed='cc7f12ba-6eef-4bc0-9432-99f9875eca09';$pendingResult=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[uc_event($unconfirmed,'2026-10-30T03:30:00Z','2026-10-30T05:30:00Z','PENDING_APPROVAL')]);
    uc_check($pendingResult['skipped']===1&&uc_appointment($unconfirmed)===null,'New pending lecture is skipped and creates no scheduled appointment');
    UniCalendarService::import($batch,'2026-10-01','2026-10-31',[array_replace($changed,['status'=>'SCHEDULED'])]);
    uc_check(uc_appointment($first)['status']==='scheduled','Explicit scheduled status creates a usable active appointment');
    UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$changed]);
    $recreated=(int)uc_appointment($first)['id'];PlannerService::remove('planner_appointments',$recreated);
    UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$changed]);
    uc_check(uc_appointment($first)!==null&&uc_appointment($first)['status']==='scheduled','User-deleted linked appointment is recreated on next valid sync');
    $rollover='cc7f12ba-6eef-4bc0-9432-99f9875eca04';
    UniCalendarService::import($batch,'2026-10-01','2026-10-31',[uc_event($rollover,'2026-10-30T20:00:00Z','2026-10-30T21:00:00Z')]);
    uc_check(uc_appointment($rollover)['starts_at']==='2026-10-31 01:30','Timezone conversion handles next-day rollover');
    $rolling=uc_event($rollover,'2026-10-30T20:00:00Z','2026-10-30T21:00:00Z');
    for($i=1;$i<=51;$i++){$rolling['moduleName']='Changed lecture '.$i;UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$rolling]);}
    $latest=Db::one('SELECT * FROM uni_calendar_notifications ORDER BY id DESC LIMIT 1');$calendarAlerts=uc_calendar_alerts();$latestId=hash('sha256','nibm-sync:'.$latest['id']);
    uc_check(count($calendarAlerts)===50&&$calendarAlerts[0]['id']===$latestId,'Notification panel receives exactly the latest fifty calendar summaries in newest-first order');
    uc_check(count(array_unique(array_column($calendarAlerts,'id')))===50,'Each calendar change summary has a unique stable notification identity');
    Db::run('INSERT INTO notification_reads(key,read_at) VALUES (?,?)',[$latestId,'2026-10-30 12:00:00']);
    uc_check(uc_calendar_alerts()[0]['read']===true,'Saved read state is reflected in calendar notifications');
    uc_check(uc_calendar_alerts()[0]['id']===$latestId&&uc_calendar_alerts()[0]['read']===true,'Refreshing alerts preserves the same notification identity and read state');
    $originalAppointment=(int)uc_appointment($rollover)['id'];
    UniCalendarService::configure('https://aca.mynibm.com/dashboard?batch='.$otherBatch);
    uc_check(uc_appointment($rollover)['status']==='cancelled','Selecting a different batch stops the previous batch reminders');
    uc_check(PlannerService::requireRow('planner_appointments',$manual)===$manualBefore,'Batch switching leaves unrelated appointments unchanged');
    UniCalendarService::configure($url);$notificationCount=uc_notification_count();
    $restored=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$rolling]);
    uc_check((int)uc_appointment($rollover)['id']===$originalAppointment&&uc_appointment($rollover)['status']==='scheduled','Returning to a batch restores unchanged confirmed lectures using the original appointment');
    uc_check($restored['updated']===1&&uc_notification_count()===$notificationCount+1,'Restoring a batch reminder records one update notification');
    $repeated=UniCalendarService::import($batch,'2026-10-01','2026-10-31',[$rolling]);
    uc_check($repeated['created']===0&&$repeated['updated']===0&&$repeated['cancelled']===0&&uc_notification_count()===$notificationCount+1,'Repeated sync after restoring a batch does not repeat its notification');
    UniCalendarService::pause();$paused=uc_snapshot();uc_check(!UniCalendarService::config()['enabled'],'Pause disables synchronization');
    uc_reject(fn()=>UniCalendarService::import($batch,'2026-10-01','2026-10-31',[]),'Paused connection refuses imports');
    uc_check(uc_snapshot()===$paused,'Pause and rejected background sync preserve imported calendar');
    uc_check(Db::one('PRAGMA integrity_check')['integrity_check']==='ok'&&Db::all('PRAGMA foreign_key_check')===[],'Calendar data passes SQLite integrity and foreign key checks');
    echo "$checks University calendar service checks passed.\n";
} finally { Db::close(); }

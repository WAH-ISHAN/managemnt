<?php
return function(Router $r) {
    $r->get('/uni/calendar/subscription',function(){
        $view=$_GET['view'] ?? 'month'; if (!is_string($view) || !in_array($view,['month','week','day','agenda'],true)) $view='month';
        $date=$_GET['date'] ?? date('Y-m-d'); if (!is_string($date)) throw new InvalidArgumentException(t('Choose a valid date.')); valid_date($date);
        $selected=new DateTimeImmutable($date); $from=$selected; $until=$selected;
        if ($view==='month') { $from=$selected->modify('first day of this month'); $until=$selected->modify('last day of this month'); }
        elseif ($view==='week') { $from=$selected->modify('monday this week'); $until=$from->modify('+6 days'); }
        elseif ($view==='agenda') { $from=$selected->modify('-1 year'); $until=$selected->modify('+1 year'); }
        $events=UniIcsSubscription::events($from->format('Y-m-d'),$until->format('Y-m-d'));
        $searchInput=$_GET['q'] ?? ''; $moduleInput=$_GET['module'] ?? '';
        if (!is_string($searchInput) || !is_string($moduleInput)) throw new InvalidArgumentException(t('Search is too long.'));
        $search=trim($searchInput); $module=trim($moduleInput);
        if (mb_strlen($search)>100 || mb_strlen($module)>150) throw new InvalidArgumentException(t('Search is too long.'));
        if ($search!=='' || $module!=='') $events=array_values(array_filter($events,static fn($item)=>($search==='' || mb_stripos($item['title'].' '.$item['notes'].' '.$item['location'],$search)!==false) && ($module==='' || $item['title']===$module)));
        $detail=$_GET['event'] ?? null;
        $lecture=is_string($detail) && ctype_digit($detail) ? Db::one('SELECT p.* FROM uni_ics_events u JOIN planner_appointments p ON p.id=u.appointment_id WHERE p.id=?',[(int)$detail]) : null;
        $changesId=$_GET['changes'] ?? null;
        $changes=is_string($changesId) && ctype_digit($changesId) ? Db::one("SELECT * FROM uni_calendar_notifications WHERE id=? AND batch_id='ics'",[(int)$changesId]) : null;
        return View::render(__DIR__.'/views/subscription.php',['title'=>t('University calendar'),'status'=>UniIcsSubscription::status(),'view'=>$view,'date'=>$date,'from'=>$from,'until'=>$until,'events'=>$events,'search'=>$search,'module'=>$module,'lecture'=>$lecture,'changes'=>$changes]);
    });
    $r->get('/uni/calendar/subscription/status',function(){ header('Content-Type: application/json'); header('Cache-Control: no-store'); return json_encode(UniIcsSubscription::status()+['csrf'=>$_SESSION['csrf']],JSON_THROW_ON_ERROR); });
    $r->post('/uni/calendar/subscription/connect',function(){ UniIcsSubscription::connect(text_input('calendar_url',2048)); return redirect('/uni/calendar/subscription','Calendar connected and synchronized.'); });
    $r->post('/uni/calendar/subscription/sync',function(){ UniIcsSubscription::sync(); return redirect('/uni/calendar/subscription','Calendar synchronized.'); });
    $r->post('/uni/calendar/subscription/disconnect',function(){ UniIcsSubscription::disconnect(); return redirect('/uni/calendar/subscription','Calendar disconnected. Saved lectures remain available.'); });
    $r->post('/uni/calendar/subscription/automatic',function(){ Db::set('uni_ics_automatic',isset($_POST['enabled'])?'1':'0'); return redirect('/uni/calendar/subscription','Calendar synchronization preference saved.'); });
    $r->get('/uni/calendar',function(){
        $month=$_GET['month'] ?? date('Y-m');
        if (!is_string($month)) throw new InvalidArgumentException(t('Choose a calendar month.'));
        valid_month($month);
        $changesId=$_GET['changes'] ?? null;
        $changes=is_string($changesId) && ctype_digit($changesId) ? Db::one('SELECT * FROM uni_calendar_notifications WHERE id=?',[(int)$changesId]) : null;
        $eventId=$_GET['event'] ?? null;
        $lecture=is_string($eventId) && ctype_digit($eventId) ? Db::one('SELECT p.* FROM uni_calendar_events u JOIN planner_appointments p ON p.id=u.appointment_id WHERE p.id=? AND u.batch_id=?',[(int)$eventId,Db::setting('uni_calendar_batch')]) : null;
        return View::render(__DIR__.'/views/calendar.php',['title'=>t('Lecture calendar'),'calendar'=>UniCalendarService::config(),'month'=>$month,'events'=>UniCalendarService::events($month),'changes'=>$changes,'lecture'=>$lecture]);
    });
    $r->get('/uni/calendar/config',function(){ header('Content-Type: application/json'); return json_encode(UniCalendarService::config()+['csrf'=>$_SESSION['csrf']],JSON_THROW_ON_ERROR); });
    $r->post('/uni/calendar/connect',function(){ UniCalendarService::configure(text_input('portal_url',2048)); return redirect('/uni/calendar','NIBM batch connected. Open NIBM sign-in to start syncing.'); });
    $r->post('/uni/calendar/pause',function(){ UniCalendarService::pause(); return redirect('/uni/calendar','Calendar sync paused. Saved lectures remain in your planner.'); });
    $r->post('/uni/calendar/error',function(){ UniCalendarService::error(text_input('message',200)); header('Content-Type: application/json'); return '{"ok":true}'; });
    $r->post('/uni/calendar/import',function(){
        $raw=$_POST['lectures'] ?? '';
        if (!is_string($raw) || strlen($raw)>1572864) throw new InvalidArgumentException(t('The calendar snapshot is too large.'));
        try { $lectures=json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new InvalidArgumentException(t('The calendar snapshot is not valid JSON.')); }
        if (!is_array($lectures) || !str_starts_with(ltrim($raw),'[')) throw new InvalidArgumentException(t('The calendar snapshot must contain a lecture array.'));
        $result=UniCalendarService::import(text_input('batch_id',36),text_input('window_start',10),text_input('window_end',10),$lectures);
        header('Content-Type: application/json'); return json_encode(['ok'=>true,'counts'=>$result],JSON_THROW_ON_ERROR);
    });
};

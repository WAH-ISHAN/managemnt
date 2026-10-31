<?php
return function(Router $r) {
    $r->get('/uni/calendar',function(){
        $month=$_GET['month'] ?? date('Y-m');
        if (!is_string($month)) throw new InvalidArgumentException('Choose a calendar month.');
        valid_month($month);
        return View::render(__DIR__.'/views/calendar.php',['title'=>'Lecture calendar','calendar'=>UniCalendarService::config(),'month'=>$month,'events'=>UniCalendarService::events($month)]);
    });
    $r->get('/uni/calendar/config',function(){ header('Content-Type: application/json'); return json_encode(UniCalendarService::config()+['csrf'=>$_SESSION['csrf']],JSON_THROW_ON_ERROR); });
    $r->post('/uni/calendar/connect',function(){ UniCalendarService::configure(text_input('portal_url',2048)); return redirect('/uni/calendar','NIBM batch connected. Open NIBM sign-in to start syncing.'); });
    $r->post('/uni/calendar/pause',function(){ UniCalendarService::pause(); return redirect('/uni/calendar','Calendar sync paused. Saved lectures remain in your planner.'); });
    $r->post('/uni/calendar/error',function(){ UniCalendarService::error(text_input('message',200)); header('Content-Type: application/json'); return '{"ok":true}'; });
    $r->post('/uni/calendar/import',function(){
        $raw=$_POST['lectures'] ?? '';
        if (!is_string($raw) || strlen($raw)>1572864) throw new InvalidArgumentException('The calendar snapshot is too large.');
        try { $lectures=json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new InvalidArgumentException('The calendar snapshot is not valid JSON.'); }
        if (!is_array($lectures) || !str_starts_with(ltrim($raw),'[')) throw new InvalidArgumentException('The calendar snapshot must contain a lecture array.');
        $result=UniCalendarService::import(text_input('batch_id',36),text_input('window_start',10),text_input('window_end',10),$lectures);
        header('Content-Type: application/json'); return json_encode(['ok'=>true,'counts'=>$result],JSON_THROW_ON_ERROR);
    });
};

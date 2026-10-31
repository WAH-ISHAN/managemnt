<?php
return function (Router $r) {
    $r->get('/focus',function () {
        $start=(new DateTimeImmutable('today'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $end=(new DateTimeImmutable('tomorrow'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return View::render(__DIR__.'/views/index.php',['title'=>'Study focus','active'=>FocusService::active(),'minutes'=>FocusService::minutes($start,$end),'history'=>Db::all('SELECT * FROM focus_sessions ORDER BY id DESC LIMIT 30')]);
    });
    $r->post('/focus/start',function () { FocusService::start(text_input('subject',150),int_range($_POST['minutes'] ?? '',1,120),text_input('kind')); return redirect('/focus','Your session has started.'); });
    $r->post('/focus/{id}/action',function ($p) { FocusService::action(record_id($p['id']),text_input('action')); return redirect('/focus','Session updated.'); });
};

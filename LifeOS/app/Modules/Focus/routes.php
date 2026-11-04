<?php
return function (Router $r) {
    $r->get('/focus',function () {
        $start=(new DateTimeImmutable('today'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $end=(new DateTimeImmutable('tomorrow'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return View::render(__DIR__.'/views/index.php',['title'=>t('Study focus'),'active'=>FocusService::active(),'minutes'=>FocusService::minutes($start,$end),'tasks'=>Db::all("SELECT id,title FROM tasks WHERE status='open' ORDER BY due,id LIMIT 200"),'habits'=>Db::all('SELECT id,title FROM planner_habits WHERE active=1'),'logs'=>Db::all('SELECT l.*,t.title FROM focus_task_logs l JOIN tasks t ON t.id=l.task_id ORDER BY l.session_id DESC LIMIT 30'),'history'=>Db::all('SELECT * FROM focus_sessions ORDER BY id DESC LIMIT 30')]);
    });
    $r->post('/focus/start',function () { FocusService::start(text_input('subject',150),int_range($_POST['minutes'] ?? '',1,120),text_input('kind'),null,$_POST); return redirect('/focus','Your session has started.'); });
    $r->post('/focus/{id}/action',function ($p) { FocusService::action(record_id($p['id']),text_input('action')); return redirect('/focus','Session updated.'); });
};

<?php
return function (Router $r) {
    $r->get('/planner',function () {
        $date=PlannerService::dateInput($_GET['date'] ?? date('Y-m-d')); $tab=$_GET['view'] ?? 'today'; if (!in_array($tab,['today','tasks','habits','routines','appointments'],true)) throw new InvalidArgumentException('Choose a planner page.');
        $taskList=PlannerService::taskList($_GET['filter'] ?? 'all',$_GET['q'] ?? '',$date); $tasks=$taskList['rows'];
        $habits=Db::all('SELECT h.*,EXISTS(SELECT 1 FROM planner_habit_checks c WHERE c.habit_id=h.id AND c.date=?) checked,(SELECT COUNT(*) FROM planner_habit_checks c WHERE c.habit_id=h.id AND c.date>=? AND c.date<=?) week_count FROM planner_habits h ORDER BY active DESC,id',[$date,date('Y-m-d',strtotime($date.' -6 days')),$date]);
        foreach ($habits as &$habit) $habit['streak']=PlannerService::streak((int)$habit['id']); unset($habit);
        $routines=Db::all('SELECT r.*,EXISTS(SELECT 1 FROM planner_routine_checks c WHERE c.routine_id=r.id AND c.date=?) checked FROM planner_routines r ORDER BY active DESC,time,id',[$date]);
        $appointments=Db::all('SELECT * FROM planner_appointments ORDER BY starts_at DESC LIMIT 100');
        $dayAppointments=PlannerService::appointmentsOn($date);
        $dayTasks=Db::all("SELECT * FROM tasks WHERE status='open' AND (due IS NULL OR due<=?) ORDER BY due IS NULL,due,id LIMIT 200",[$date]);
        $dayTaskCount=(int)Db::one("SELECT COUNT(*) n FROM tasks WHERE status='open' AND (due IS NULL OR due<=?)",[$date])['n'];
        return View::render(__DIR__.'/views/index.php',['title'=>'Daily Planner','tab'=>$tab,'date'=>$date,'taskList'=>$taskList,'tasks'=>$tasks,'habits'=>$habits,'routines'=>$routines,'appointments'=>$appointments,'dayAppointments'=>$dayAppointments,'dayTasks'=>$dayTasks,'dayTaskCount'=>$dayTaskCount]);
    });
    $r->post('/planner/tasks',function () { PlannerService::saveTask(null,$_POST); return redirect('/planner?view=tasks','Task added.'); });
    $r->post('/planner/tasks/{id}/edit',function ($p) { $return=PlannerService::taskReturnUrl($_POST); PlannerService::saveTask(record_id($p['id']),$_POST); return redirect($return,'Task updated.'); });
    $r->post('/planner/tasks/{id}/status',function ($p) { $return=PlannerService::taskReturnUrl($_POST); PlannerService::taskStatus(record_id($p['id']),text_input('status')); return redirect($return,'Task status saved.'); });
    $r->post('/planner/tasks/{id}/delete',function ($p) { $return=PlannerService::taskReturnUrl($_POST); PlannerService::deleteTask(record_id($p['id'])); return redirect($return,'Task removed.'); });
    foreach (['habits'=>'Habit','routines'=>'Routine','appointments'=>'Appointment'] as $route=>$entity) {
        $r->post('/planner/'.$route,function () use ($route,$entity) { ('PlannerService')::{'save'.$entity}(null,$_POST); return redirect('/planner?view='.$route,$entity.' added.'); });
        $r->post('/planner/'.$route.'/{id}/edit',function ($p) use ($route,$entity) { ('PlannerService')::{'save'.$entity}(record_id($p['id']),$_POST); return redirect('/planner?view='.$route,$entity.' updated.'); });
        $r->post('/planner/'.$route.'/{id}/delete',function ($p) use ($route,$entity) { PlannerService::remove('planner_'.$route,record_id($p['id'])); return redirect('/planner?view='.$route,$entity.' removed.'); });
    }
    $r->post('/planner/habits/{id}/check',function ($p) { PlannerService::checkHabit(record_id($p['id']),text_input('date')); return redirect('/planner?view=habits&date='.urlencode(text_input('date')),'Habit check-in updated.'); });
    $r->post('/planner/routines/{id}/check',function ($p) { PlannerService::checkRoutine(record_id($p['id']),text_input('date')); return redirect('/planner?view=routines&date='.urlencode(text_input('date')),'Routine completion updated.'); });
};

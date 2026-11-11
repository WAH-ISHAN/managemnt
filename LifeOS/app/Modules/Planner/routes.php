<?php
return function (Router $r) {
    $r->get('/planner/capture',fn()=>View::render(__DIR__.'/views/capture.php',['title'=>t('Quick Capture'),'captures'=>Db::all("SELECT * FROM capture_inbox WHERE status='pending' ORDER BY id DESC LIMIT 100")]));
    $r->post('/planner/capture',function () { CaptureService::add(text_input('text',2000)); return redirect('/planner/capture','Capture saved. Review it below.'); });
    $r->post('/planner/capture/{id}/convert',function ($p) { CaptureService::convert(record_id($p['id']),$_POST); return redirect('/planner/capture','Task created.'); });
    $r->post('/planner/capture/{id}/dismiss',function ($p) { Db::run("UPDATE capture_inbox SET status='dismissed' WHERE id=? AND status='pending'",[record_id($p['id'])]); return redirect('/planner/capture'); });
    $r->get('/planner/planning',fn()=>View::render(__DIR__.'/views/planning.php',['title'=>t('Time planning'),'preferences'=>PlanningService::preferences(),'preview'=>$_SESSION['planning_preview'] ?? null,'blocks'=>Db::all('SELECT b.*,t.title FROM planner_blocks b JOIN tasks t ON t.id=b.task_id WHERE b.ends_at>? ORDER BY b.starts_at LIMIT 200',[date('Y-m-d').' 00:00']),'batches'=>Db::all('SELECT * FROM planner_batches ORDER BY id DESC LIMIT 30')]));
    $r->post('/planner/planning/preferences',function () { PlanningService::configure($_POST); return redirect('/planner/planning','Availability saved.'); });
    $r->post('/planner/planning/preview',function () { $date=valid_date(text_input('date')); if ($date<date('Y-m-d')) throw new InvalidArgumentException(t('Choose today or a future day.')); $_SESSION['planning_preview']=PlanningService::preview($date,isset($_POST['reschedule'])); return redirect('/planner/planning'); });
    $r->post('/planner/planning/apply',function () { if (!isset($_SESSION['planning_preview'])) throw new InvalidArgumentException(t('Preview a plan first.')); PlanningService::apply($_SESSION['planning_preview']); unset($_SESSION['planning_preview']); return redirect('/planner/planning','Plan applied. You can undo it below.'); });
    $r->post('/planner/planning/{id}/undo',function ($p) { PlanningService::undo(record_id($p['id'])); return redirect('/planner/planning','Plan undone.'); });
    $r->post('/planner/blocks/{id}/pin',function ($p) { Db::run('UPDATE planner_blocks SET pinned=1-pinned WHERE id=?',[record_id($p['id'])]); return redirect('/planner/planning'); });
    $r->post('/planner/blocks/{id}/delete',function ($p) { Db::run('DELETE FROM planner_blocks WHERE id=? AND pinned=0',[record_id($p['id'])]); return redirect('/planner/planning','Unpinned block removed.'); });
    $r->post('/planner/tasks/{id}/unarchive',function ($p) { Db::run('UPDATE tasks SET archived_at=NULL WHERE id=?',[record_id($p['id'])]); return redirect('/planner?view=tasks&filter=archived','Task restored from archive.'); });
    $r->get('/planner/holidays',function () {
        $year=SriLankaCalendarService::year($_GET['year'] ?? date('Y'));
        SriLankaCalendarService::seedBundled();
        return View::render(__DIR__.'/views/holidays.php',['title'=>t('Sri Lanka holidays'),'year'=>$year,'calendar'=>SriLankaCalendarService::listing($year)]);
    });
    $r->post('/planner/holidays/holiday',function () {
        $year=int_range($_POST['year']??'',2000,2100); $date=valid_date(text_input('date')); if ((int)substr($date,0,4)!==$year) throw new InvalidArgumentException(t('Holiday date must be inside the selected year.'));
        $dataset=Db::one("SELECT id FROM holiday_datasets WHERE country='LK' AND year=?",[$year]);
        if (!$dataset) { $id=Db::run('INSERT INTO holiday_datasets(country,year,revision,source_title,source_url,published_on) VALUES (?,?,?,?,?,?)',['LK',$year,'user-'.$year,'User-entered local calendar','',date('Y-m-d')]); } else $id=(int)$dataset['id'];
        Db::run('INSERT INTO holidays(dataset_id,date,title_en,title_si,title_ta,is_public,is_bank,is_mercantile,is_poya,jurisdiction) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$date,text_input('title_en'),text_input('title_si',200,false),text_input('title_ta',200,false),isset($_POST['is_public'])?1:0,isset($_POST['is_bank'])?1:0,isset($_POST['is_mercantile'])?1:0,isset($_POST['is_poya'])?1:0,text_input('jurisdiction',120,false)]);
        return redirect('/planner/holidays?year='.$year,'Holiday saved.');
    });
    $r->get('/planner',function () {
        $date=PlannerService::dateInput($_GET['date'] ?? date('Y-m-d')); $tab=$_GET['view'] ?? 'today'; if (!in_array($tab,['today','calendar','tasks','habits','routines','appointments'],true)) throw new InvalidArgumentException(t('Choose a planner page.'));
        $taskList=PlannerService::taskList($_GET['filter'] ?? 'all',$_GET['q'] ?? '',$date); $tasks=$taskList['rows'];
        $habits=Db::all('SELECT h.*,EXISTS(SELECT 1 FROM planner_habit_checks c WHERE c.habit_id=h.id AND c.date=?) checked,(SELECT COUNT(*) FROM planner_habit_checks c WHERE c.habit_id=h.id AND c.date>=? AND c.date<=?) week_count FROM planner_habits h ORDER BY active DESC,id',[$date,date('Y-m-d',strtotime($date.' -6 days')),$date]);
        foreach ($habits as &$habit) $habit['streak']=PlannerService::streak((int)$habit['id']); unset($habit);
        $routines=Db::all('SELECT r.*,EXISTS(SELECT 1 FROM planner_routine_checks c WHERE c.routine_id=r.id AND c.date=?) checked FROM planner_routines r ORDER BY active DESC,time,id',[$date]);
        $selectedAppointment=$tab==='appointments' && isset($_GET['appointment']) ? PlannerService::requireRow('planner_appointments',record_id($_GET['appointment'])) : null;
        $appointments=$selectedAppointment ? [$selectedAppointment] : Db::all('SELECT * FROM planner_appointments ORDER BY starts_at DESC LIMIT 100');
        $dayAppointments=PlannerService::appointmentsOn($date);
        $dayTasks=Db::all("SELECT * FROM tasks WHERE status='open' AND (due IS NULL OR due<=?) ORDER BY due IS NULL,due,id LIMIT 200",[$date]);
        $dayTaskCount=(int)Db::one("SELECT COUNT(*) n FROM tasks WHERE status='open' AND (due IS NULL OR due<=?)",[$date])['n'];
        return View::render(__DIR__.'/views/index.php',['title'=>t('Daily Planner'),'tab'=>$tab,'date'=>$date,'taskList'=>$taskList,'tasks'=>$tasks,'habits'=>$habits,'routines'=>$routines,'appointments'=>$appointments,'dayAppointments'=>$dayAppointments,'dayTasks'=>$dayTasks,'dayTaskCount'=>$dayTaskCount,'selectedAppointment'=>$selectedAppointment,'calendar'=>$tab==='calendar' ? PlannerService::calendarMonth($date) : null]);
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

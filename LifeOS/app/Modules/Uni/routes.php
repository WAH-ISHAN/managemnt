<?php
return function (Router $r) {
    (require __DIR__.'/calendar-routes.php')($r);
    $r->get('/uni/module/{id}/edit',function($p){
        $module=UniService::module(record_id($p['id']));
        return View::render(__DIR__.'/views/add.php',['title'=>'Edit module','type'=>'module','editingModule'=>$module,'modules'=>[]]);
    });
    $r->get('/uni/add/{type}',function($p){
        if(!in_array($p['type'],['module','assignment','exam','class'],true)) throw new InvalidArgumentException('Choose a university entry type.');
        return View::render(__DIR__.'/views/add.php',['title'=>'Add university entry','type'=>$p['type'],'modules'=>Db::all('SELECT * FROM uni_modules ORDER BY semester DESC,name')]);
    });
    $r->get('/uni',function () {
        $tab=$_GET['view'] ?? ((isset($_GET['module']) || isset($_GET['status'])) ? 'assignments' : 'overview'); if (!in_array($tab,['overview','assignments','modules','exams','timetable'],true)) throw new InvalidArgumentException('Choose a university page.');
        $module=optional_id($_GET['module'] ?? ''); $status=$_GET['status'] ?? '';
        if (!in_array($status,['','todo','doing','done'],true)) throw new InvalidArgumentException('Choose a valid status.');
        $sql='SELECT a.*,m.name module FROM assignments a JOIN uni_modules m ON m.id=a.module_id WHERE 1=1'; $args=[];
        if ($module) { $sql.=' AND a.module_id=?'; $args[]=$module; }
        if ($status!=='') { $sql.=' AND a.status=?'; $args[]=$status; }
        $sql.=" ORDER BY (a.status='done'),a.due_date IS NULL,a.due_date,a.id";
        return View::render(__DIR__.'/views/index.php',['title'=>'University','tab'=>$tab,'moduleFilter'=>$module,'statusFilter'=>$status,'modules'=>Db::all('SELECT * FROM uni_modules ORDER BY semester DESC,name'),'assignments'=>Db::all($sql,$args),'exams'=>Db::all('SELECT x.*,m.name module FROM exams x JOIN uni_modules m ON m.id=x.module_id ORDER BY x.date'),'timetable'=>Db::all('SELECT t.*,m.name module FROM timetable t JOIN uni_modules m ON m.id=t.module_id ORDER BY t.day,t.start_time'),'gpa'=>UniService::gpa(),'grades'=>array_keys(UniService::points()),'pending'=>(int)Db::one("SELECT COUNT(*) n FROM assignments WHERE status<>'done'")['n']]);
    });
    $r->post('/uni/module',function () { Db::run('INSERT INTO uni_modules(code,name,credits,semester) VALUES (?,?,?,?)',[text_input('code',30,false),text_input('name',150),int_range($_POST['credits'] ?? '',0,100),text_input('semester',100,false)]); return redirect('/uni?view=modules','Module added.'); });
    $r->post('/uni/module/{id}/edit',function($p){
        $id=record_id($p['id']); UniService::module($id);
        Db::run('UPDATE uni_modules SET code=?,name=?,credits=?,semester=? WHERE id=?',[text_input('code',30,false),text_input('name',150),int_range($_POST['credits'] ?? '',0,100),text_input('semester',100,false),$id]);
        return redirect('/uni?view=modules','Module updated. Linked assignments, exams and classes are preserved.');
    });
    $r->post('/uni/module/{id}/grade',function ($p) { $id=record_id($p['id']); UniService::module($id); $grade=text_input('grade',12,false); if ($grade!=='' && !array_key_exists($grade,UniService::points())) throw new InvalidArgumentException('Choose a valid grade.'); Db::run('UPDATE uni_modules SET grade=? WHERE id=?',[$grade,$id]); return redirect('/uni?view=modules','Grade saved.'); });
    $r->post('/uni/module/{id}/delete',function ($p) { Db::run('DELETE FROM uni_modules WHERE id=?',[record_id($p['id'])]); return redirect('/uni?view=modules','Module and its assignments, exams, and timetable removed.'); });
    $r->post('/uni/assignment',function () { UniService::withModule($_POST,fn($module)=>Db::run('INSERT INTO assignments(module_id,title,due_date,weight) VALUES (?,?,?,?)',[$module,text_input('title'),optional_date($_POST['due_date'] ?? ''),percent_value($_POST['weight'] ?? '',true)])); return redirect('/uni?view=assignments','Assignment added.'); });
    $r->post('/uni/assignment/{id}/status',function ($p) { $status=text_input('status'); if (!in_array($status,['todo','doing','done'],true)) throw new InvalidArgumentException('Invalid assignment status.'); Db::run('UPDATE assignments SET status=? WHERE id=?',[$status,record_id($p['id'])]); return redirect('/uni?view=assignments','Assignment updated.'); });
    $r->post('/uni/assignment/{id}/marks',function ($p) { Db::run('UPDATE assignments SET marks=? WHERE id=?',[percent_value($_POST['marks'] ?? '',true),record_id($p['id'])]); return redirect('/uni?view=assignments','Marks saved.'); });
    $r->post('/uni/assignment/{id}/delete',function ($p) { Db::run('DELETE FROM assignments WHERE id=?',[record_id($p['id'])]); return redirect('/uni?view=assignments','Assignment removed.'); });
    $r->post('/uni/exam',function () { UniService::withModule($_POST,fn($id)=>Db::run('INSERT INTO exams(module_id,date,venue) VALUES (?,?,?)',[$id,valid_date(text_input('date')),text_input('venue',200,false)])); return redirect('/uni?view=exams','Exam added.'); });
    $r->post('/uni/exam/{id}/marks',function ($p) { Db::run('UPDATE exams SET marks=? WHERE id=?',[percent_value($_POST['marks'] ?? '',true),record_id($p['id'])]); return redirect('/uni?view=exams','Exam marks saved.'); });
    $r->post('/uni/exam/{id}/delete',function ($p) { Db::run('DELETE FROM exams WHERE id=?',[record_id($p['id'])]); return redirect('/uni?view=exams','Exam removed.'); });
    $r->post('/uni/timetable',function () {
        $start=text_input('start_time'); $end=text_input('end_time');
        foreach ([$start,$end] as $time) if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time)) throw new InvalidArgumentException('Choose valid class times.');
        if ($end<=$start) throw new InvalidArgumentException('End time must be after start time.');
        UniService::withModule($_POST,fn($id)=>Db::run('INSERT INTO timetable(module_id,day,start_time,end_time,venue) VALUES (?,?,?,?,?)',[$id,int_range($_POST['day'] ?? '',1,7),$start,$end,text_input('venue',200,false)])); return redirect('/uni?view=timetable','Class added.');
    });
    $r->post('/uni/timetable/{id}/delete',function ($p) { Db::run('DELETE FROM timetable WHERE id=?',[record_id($p['id'])]); return redirect('/uni?view=timetable','Class removed.'); });
};

<?php
return function (Router $r) {
    $r->get('/career',function () { $tab=$_GET['view'] ?? 'skills'; if (!in_array($tab,['skills','courses','applications'],true)) throw new InvalidArgumentException('Choose a career page.'); return View::render(__DIR__.'/views/index.php',['title'=>'Career','tab'=>$tab,'skills'=>Db::all('SELECT * FROM career_skills ORDER BY name'),'courses'=>Db::all("SELECT c.*,s.name skill FROM career_courses c LEFT JOIN career_skills s ON s.id=c.skill_id ORDER BY (c.status='completed'),c.due_date IS NULL,c.due_date,c.id DESC"),'applications'=>Db::all('SELECT * FROM career_applications ORDER BY created_at DESC,id DESC')]); });
    foreach (['skills'=>'Skill','courses'=>'Course','applications'=>'Application'] as $route=>$entity) {
        $r->post('/career/'.$route,function () use ($route,$entity) { ('CareerService')::{'save'.$entity}(null,$_POST); return redirect('/career?view='.$route,$entity.' added.'); });
        $r->post('/career/'.$route.'/{id}/edit',function ($p) use ($route,$entity) { ('CareerService')::{'save'.$entity}(record_id($p['id']),$_POST); return redirect('/career?view='.$route,$entity.' updated.'); });
        $r->post('/career/'.$route.'/{id}/delete',function ($p) use ($route,$entity) { CareerService::remove('career_'.$route,record_id($p['id'])); return redirect('/career?view='.$route,$entity.' removed.'); });
    }
};

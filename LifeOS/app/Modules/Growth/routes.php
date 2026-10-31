<?php
return function (Router $r) {
    $r->get('/growth',fn()=>View::render(__DIR__.'/views/index.php',['title'=>'Goals & Growth','goals'=>GrowthService::goals()]));
    $r->post('/growth',function () { $id=GrowthService::saveGoal(null,$_POST); return redirect('/growth/'.$id,'Goal created. Break it into actionable steps.'); });
    $r->get('/growth/{id}',function ($p) { $id=record_id($p['id']); $goal=GrowthService::goal($id); return View::render(__DIR__.'/views/show.php',['title'=>$goal['title'],'goal'=>$goal,'goals'=>GrowthService::goals(),'steps'=>Db::all('SELECT s.*,p.title prerequisite,p.status prerequisite_status FROM personal_goal_steps s LEFT JOIN personal_goal_steps p ON p.id=s.depends_on WHERE s.goal_id=? ORDER BY s.sort,s.id',[$id])]); });
    $r->post('/growth/{id}/edit',function ($p) { $id=record_id($p['id']); GrowthService::saveGoal($id,$_POST); return redirect('/growth/'.$id,'Goal updated.'); });
    $r->post('/growth/{id}/delete',function ($p) { $id=record_id($p['id']); GrowthService::goal($id); Db::run('DELETE FROM personal_goals WHERE id=?',[$id]); return redirect('/growth','Goal and its steps removed.'); });
    $r->post('/growth/{id}/steps',function ($p) { $id=record_id($p['id']); GrowthService::saveStep($id,null,$_POST); return redirect('/growth/'.$id,'Step added.'); });
    $r->post('/growth/{id}/steps/{step}/edit',function ($p) { $id=record_id($p['id']); GrowthService::saveStep($id,record_id($p['step']),$_POST); return redirect('/growth/'.$id,'Step updated.'); });
    $r->post('/growth/{id}/steps/{step}/status',function ($p) { $id=record_id($p['id']); GrowthService::stepStatus($id,record_id($p['step']),text_input('status')); return redirect('/growth/'.$id,'Step status saved.'); });
    $r->post('/growth/{id}/steps/{step}/delete',function ($p) { $id=record_id($p['id']); GrowthService::deleteStep($id,record_id($p['step'])); return redirect('/growth/'.$id,'Step removed.'); });
};

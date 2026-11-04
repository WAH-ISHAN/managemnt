<?php
return function(Router $r){
 $r->get('/study',function(){$kind=$_GET['page']??'attendance';if(!is_string($kind))throw new InvalidArgumentException(t('Choose a study page.'));StudyService::definition($kind);$search=is_string($_GET['q']??'')?mb_substr($_GET['q']??'',0,200):'';return View::render(__DIR__.'/views/index.php',['title'=>t('University study tools'),'kind'=>$kind,'search'=>$search,'rows'=>StudyService::rows($kind,$search),'editing'=>isset($_GET['edit'])?StudyService::get(record_id($_GET['edit']),$kind):null]);});
 $r->post('/study/{kind}/save',function($p){StudyService::save($p['kind'],$_POST,optional_id($_POST['id']??''));return redirect('/study?page='.urlencode($p['kind']),'Saved.');});
 $r->post('/study/{kind}/{id}/archive',function($p){StudyService::archive($p['kind'],record_id($p['id']));return redirect('/study?page='.urlencode($p['kind']),'Archived.');});
 $r->post('/study/{kind}/{id}/generate',function($p){$count=StudyService::generate($p['kind'],record_id($p['id']));return redirect('/study?page='.urlencode($p['kind']),$count.' new tasks created. Existing generated tasks were kept.');});
 $r->post('/study/cards/{id}/review',function($p){StudyService::review(record_id($p['id']),text_input('rating',10),date('Y-m-d'));return redirect('/study?page=cards','Review saved and next date scheduled.');});
 $r->post('/study/supervision/{id}/task',function($p){$id=record_id($p['id']);$d=StudyService::get($id,'supervision')['fields'];if(!$d['next_action']||!$d['next_due'])throw new InvalidArgumentException(t('Enter a next action and due date.'));StudyService::task($id,'supervision',$d['next_action'],$d['next_due']);return redirect('/study?page=supervision','Follow-up task linked.');});
 $r->get('/study/report',fn()=>View::render(__DIR__.'/views/report.php',['title'=>t('Academic CV & portfolio')]));
};

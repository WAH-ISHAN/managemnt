<?php
declare(strict_types=1);
return function(Router $r){
    $r->post('/school/calendar',function(){SriLankaCalendarService::seedBundled();$authority=text_input('authority',200,false);if($authority!==''&&!Db::one('SELECT id FROM school_term_datasets WHERE authority=?',[$authority]))throw new InvalidArgumentException(t('Choose an existing school calendar.'));Db::set('school_calendar_authority',$authority);return redirect('/school?page=timetable','School calendar selected.');});
    $r->post('/school/xp/settings',function(){SchoolService::settings($_POST);return redirect('/school','XP and badge policy saved.');});
    $r->post('/school/reward/redeem',function(){SchoolService::redeem(record_id($_POST['request_id']??''),text_input('guardian',40),text_input('guardian_pin',12));return redirect('/school?page=rewards','Reward redemption recorded once.');});
    $r->post('/school/bag/{id}/pack',function($p){SchoolService::pack(record_id($p['id']),text_input('date'));return redirect('/school?page=timetable','Bag checklist updated.');});
    $r->get('/school',function(){ $kind=$_GET['page']??'homework';if(!is_string($kind))throw new InvalidArgumentException(t('Choose a school page.'));SchoolService::definition($kind);return View::render(__DIR__.'/views/index.php',['title'=>t('School & family'),'kind'=>$kind,'rows'=>SchoolService::rows($kind),'editing'=>isset($_GET['edit'])?SchoolService::get(record_id($_GET['edit']),$kind):null]); });
    $r->post('/school/{kind}/save',function($p){SchoolService::save($p['kind'],$_POST,optional_id($_POST['id']??''));return redirect('/school?page='.urlencode($p['kind']),'Saved.');});
    $r->post('/school/{kind}/{id}/complete',function($p){SchoolService::complete($p['kind'],record_id($p['id']));return redirect('/school?page='.urlencode($p['kind']),'Completed. XP awarded once.');});
    $r->post('/school/{kind}/{id}/archive',function($p){SchoolService::archive($p['kind'],record_id($p['id']));return redirect('/school?page='.urlencode($p['kind']),'Archived.');});
    $r->post('/school/rewards/{id}/request',function($p){SchoolService::requestReward(record_id($p['id']));return redirect('/school?page=rewards','Request sent for guardian approval.');});
    $r->post('/school/requests/{id}/decision',function($p){SchoolService::decideReward(record_id($p['id']),text_input('decision',20),text_input('guardian',40),text_input('pin',12));return redirect('/school?page=rewards','Guardian decision recorded.');});
    $r->get('/school/report',function(){return View::render(__DIR__.'/views/report.php',['title'=>t('Weekly school report'),'report'=>SchoolService::summary(valid_date((string)($_GET['date']??date('Y-m-d'))))]);});
};

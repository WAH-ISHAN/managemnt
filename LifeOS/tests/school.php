<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require ROOT.'/app/Core/Router.php';require ROOT.'/app/Core/View.php';
$checks=0;function sc_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function sc_reject(callable $fn):void{try{$fn();}catch(InvalidArgumentException){sc_check(true,'Rejected invalid request');return;}throw new RuntimeException('Expected rejection');}
Db::init(':memory:');Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules'))));
$subject=SchoolService::save('subjects',['name'=>'Math <sample>','colour'=>'#345678']);
$hw=SchoolService::save('homework',['title'=>'Exercise','subject'=>(string)$subject,'due'=>date('Y-m-d'),'notes'=>'']);SchoolService::complete('homework',$hw);SchoolService::complete('homework',$hw);
sc_check(SchoolService::xp()['earned']===10,'Repeated completion earns XP once');
SchoolService::save('homework',['title'=>'Edited','subject'=>(string)$subject,'due'=>date('Y-m-d'),'notes'=>''],$hw);sc_check(SchoolService::xp()['earned']===10,'Editing retains original XP');
sc_reject(fn()=>SchoolService::save('timetable',['subject'=>(string)$subject,'day'=>'1','starts'=>'12:00','ends'=>'11:00','materials'=>'book']));
sc_reject(fn()=>SchoolService::save('homework',['title'=>'Bad','subject'=>'9999','due'=>'2026-02-30','notes'=>'']));
$reward=SchoolService::save('rewards',['title'=>'Family game','cost'=>'10','description'=>'']);SchoolService::requestReward($reward);sc_reject(fn()=>SchoolService::requestReward($reward));
sc_check(SchoolService::xp()['available']===10,'Request does not spend XP before guardian approval');
$class=SchoolService::save('tuition',['title'=>'Math','subject'=>(string)$subject,'day'=>'1','starts'=>'14:00','fee'=>'1000','due'=>date('Y-m-d')]);$fee=['class'=>(string)$class,'period'=>date('Y-m'),'amount'=>'1000','paid_on'=>date('Y-m-d')];SchoolService::save('fees',$fee);sc_reject(fn()=>SchoolService::save('fees',$fee));
SchoolService::save('mood',['date'=>date('Y-m-d'),'mood'=>'2','note'=>'PRIVATE SECRET','share'=>'private']);SchoolService::save('mood',['date'=>date('Y-m-d'),'mood'=>'4','note'=>'SHARED NOTE','share'=>'guardian']);
$report=SchoolService::summary(date('Y-m-d'));sc_check(count($report['shared_moods'])===1&&!str_contains(json_encode($report),'SECRET')&&!str_contains(json_encode($report),'SHARED NOTE'),'Parent report excludes private moods and all notes');
SchoolService::save('reading',['book'=>'Book','date'=>date('Y-m-d'),'pages'=>'12','minutes'=>'20','notes'=>'']);sc_check(SchoolService::summary(date('Y-m-d'))['reading_pages']===12,'Report totals only saved reading pages');
sc_reject(fn()=>SchoolService::archive('subjects',$subject));
sc_check(count(Db::all('SELECT * FROM income'))===0&&count(Db::all('SELECT * FROM expenses'))===0,'School ledger does not move real money');
$_SESSION=['csrf'=>'test-token'];$_SERVER['HTTP_HX_REQUEST']='true';$router=new Router();(require ROOT.'/app/Modules/School/routes.php')($router);
foreach(array_keys(SchoolService::catalog())as $kind){$_GET=['page'=>$kind];ob_start();$router->dispatch('GET','/school');$html=ob_get_clean();sc_check(str_contains($html,'School &amp; family')&&!str_contains($html,'<sample>'),'Rendered safe school page '.$kind);}
echo "$checks school checks passed.\n";

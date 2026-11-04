<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function prcheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function prreject(callable $fn):void{try{$fn();}catch(InvalidArgumentException){prcheck(true,'Rejected unsafe operation');return;}throw new RuntimeException('Expected rejection');}
Db::init(':memory:');Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules'))));
$raw=PortableRecords::raw("Person,Phone,Birthday\nAmal,0771234567,2000-01-02\n",'notion');
$rows=PortableRecords::prepare('contacts',$raw,['name'=>'person','phone'=>'phone','birthday'=>'birthday']);
$batch=PortableRecords::import('contacts',$rows);$contact=(int)Db::one('SELECT id FROM life_contacts')['id'];
prcheck(LifePlanningService::get('contacts',$contact)['birthday']==='2000-01-02','Mapped contact dates preserved');
prreject(fn()=>PortableRecords::import('contacts',$rows));
Db::run('UPDATE life_contacts SET phone=? WHERE id=?',['0111234567',$contact]);
prreject(fn()=>PlatformService::undo($batch));
PlatformService::undoEdit((int)Db::one('SELECT MAX(id) id FROM platform_history')['id']);
PlatformService::undo($batch);prcheck(Db::all('SELECT * FROM life_contacts')===[],'Unchanged contact import undo');
prreject(fn()=>PortableRecords::prepare('contacts',[['name'=>'Invalid','birthday'=>'2026-02-30']],[]));
prreject(fn()=>PortableRecords::raw("Name,name\nA,B",'csv'));
Db::run('INSERT INTO uni_modules(code,name,credits,semester) VALUES(?,?,?,?)',['ABC','Algorithms',3,'1']);
$notes=PortableRecords::prepare('study_notes',[['module'=>'ABC','title'=>'Search trees','markdown'=>'**Revision**','tags'=>'exam']],['module_id'=>'module']);
$batch=PortableRecords::import('study_notes',$notes);$note=StudyService::rows('notes')[0];
prcheck($note['fields']['module_id']===1&&$note['fields']['markdown']==='**Revision**','Module-code mapping and Markdown preserved');
$input=array_map(fn($v)=>(string)$v,$note['fields']);$input['title']='Edited notes';StudyService::save('notes',$input,$note['id']);
prreject(fn()=>PlatformService::undo($batch));PlatformService::undoEdit((int)Db::one('SELECT MAX(id) id FROM platform_history')['id']);
prcheck(StudyService::get($note['id'],'notes')['fields']['title']==='Search trees','Academic edit undo validates through destination service');
PlatformService::undo($batch);prcheck(StudyService::rows('notes')===[],'Academic record import undo');
$ach=PortableRecords::prepare('achievements',[['title'=>'Launch','date'=>'2026-11-03','outcome'=>'Shipped','evidence'=>'https://example.com/evidence']],[]);
$batch=PortableRecords::import('achievements',$ach);$entry=EmployeeService::rows('achievements')[0];$input=array_map(fn($v)=>(string)$v,$entry['fields']);$input['title']='=SUM(1,2)';EmployeeService::save('achievements',$input,$entry['id']);
prcheck(str_contains(PortableRecords::csv('achievements'),"'=SUM"),'Rich export neutralizes spreadsheet formulas');
PlatformService::undoEdit((int)Db::one('SELECT MAX(id) id FROM platform_history')['id']);
prcheck(EmployeeService::get($entry['id'],'achievements')['fields']['title']==='Launch','Employee evidence undo');
PlatformService::undo($batch);
prreject(fn()=>PortableRecords::import('achievements',[$ach[0],array_replace($ach[0],['date'=>'invalid'])]));
prcheck(EmployeeService::rows('achievements')===[],'Invalid late row rolls back the whole import');
prcheck(Db::all('PRAGMA foreign_key_check')===[],'Foreign-key integrity');
echo "$checks portable record checks passed.\n";

<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require ROOT.'/app/Core/ProfileManager.php';
$count=0;
function ppcheck(bool $ok,string $message): void { global $count; if(!$ok) throw new RuntimeException($message); $count++; }
function ppreject(callable $fn,string $message): void { try{$fn();}catch(InvalidArgumentException $e){ppcheck(true,$message);return;}throw new RuntimeException($message); }
function ppremove(string $path): void { if(is_dir($path)){foreach(scandir($path) as $file)if($file!=='.'&&$file!=='..')ppremove($path.'/'.$file);rmdir($path);}elseif(is_file($path))unlink($path); }
$root=sys_get_temp_dir().'/lifeos-profiles-'.bin2hex(random_bytes(6)); mkdir($root,0700,true);
try {
    Db::init($root.'/lifeos.db'); Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules'))));
    Db::set('name','Adult'); Db::set('pin_hash',password_hash('1234',PASSWORD_DEFAULT)); Db::run("INSERT INTO tasks(title) VALUES ('Adult private task')");
    ProfileManager::init($root); ProfileManager::syncName('Adult');
    ppcheck(ProfileManager::directory()===$root && ProfileManager::id()==='default','Legacy default root retained');
    $child=ProfileManager::create('Child','5678');
    ppcheck((bool)preg_match('/^[a-f0-9]{32}$/D',$child),'Opaque profile ID');
    ppcheck(is_file($root.'/profiles/'.$child.'/lifeos.db')&&is_dir($root.'/profiles/'.$child.'/sessions')&&is_dir($root.'/profiles/'.$child.'/backups')&&is_dir($root.'/profiles/'.$child.'/uploads'),'Independent database and file roots');
    $db=new PDO('sqlite:'.$root.'/profiles/'.$child.'/lifeos.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    ppcheck((int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn()===0,'Adult records never copied to child');
    ppcheck(password_verify('5678',$db->query("SELECT value FROM settings WHERE key='pin_hash'")->fetchColumn()),'Own hashed child PIN');
    ppreject(fn()=>ProfileManager::select('../outside'),'Traversal profile rejected');
    ppreject(fn()=>ProfileManager::create('Bad','abc'),'Invalid new PIN rejected');
    ppreject(fn()=>ProfileManager::verify($child,'1234'),'Adult PIN cannot unlock child');
    ppreject(fn()=>ProfileManager::report($child),'No report before explicit consent');
    ppreject(fn()=>ProfileManager::link($child,'0000','5678'),'Guardian proof required');
    ppreject(fn()=>ProfileManager::link($child,'1234','0000'),'Child proof required');
    ProfileManager::link($child,'1234','5678');
    $db->exec("INSERT INTO tasks(title) VALUES ('Child homework'); INSERT INTO planner_habits(title) VALUES ('Reading')");
    $report=ProfileManager::report($child);
    ppcheck(count($report['tasks'])===1&&$report['tasks'][0]['title']==='Child homework','Guardian read report constrained to linked child');
    ppcheck(array_keys($report)===['name','tasks','habits','focus'],'Report excludes documents, journals and money');
    ppcheck(Db::one('SELECT title FROM tasks')['title']==='Adult private task','Reading child leaves adult connection intact');
    ProfileManager::policy($child,true,'1234'); ppcheck(ProfileManager::isKid($child),'Guardian enables server kid policy');
    ProfileManager::select($child); ppcheck(ProfileManager::isKid()&&ProfileManager::directory()===$root.'/profiles/'.$child,'Selected profile resolves isolated data');
    ppcheck(ProfileManager::kidAllowed('POST','/planner/tasks/4/status')&&ProfileManager::kidAllowed('POST','/focus/start'),'Kid may complete tasks and focus');
    foreach(['/settings/restore','/settings/pin','/profiles/create','/profiles/policy','/automation','/salary','/documents','/planner/tasks/4/delete'] as $route) ppcheck(!ProfileManager::kidAllowed('POST',$route),'Kid mutation blocked: '.$route);
    ppreject(fn()=>ProfileManager::policy('default',false,'5678'),'Child cannot promote or administer guardian');
    ProfileManager::revoke('default'); ProfileManager::select('default'); ppreject(fn()=>ProfileManager::report($child),'Child can revoke guardian visibility');
    PersonaService::apply('employee');
    ppreject(fn()=>TemplatePackService::install('hnd'),'Starter install cannot add an unselected role');
    foreach(TemplatePackService::catalog() as $packId=>$pack) {
        PersonaService::apply(array_values(array_unique(array_merge(PersonaService::selectedRoles(),[$pack['persona']]))));
        $before=(int)Db::one('SELECT COUNT(*) n FROM tasks')['n']; $result=TemplatePackService::install($packId);
        ppcheck($result['installed']&&$result['tasks']===3&&$result['habits']===2,'Real pack installs editable records: '.$packId);
        ppcheck((int)Db::one('SELECT COUNT(*) n FROM tasks')['n']===$before+3,'Pack creates actual tasks: '.$packId);
        ppcheck(PersonaService::enabled('Employee'),'Installing a study/school starter pack retains the existing employee role');
        ppcheck(!TemplatePackService::install($packId)['installed']&&(int)Db::one('SELECT COUNT(*) n FROM tasks')['n']===$before+3,'Repeated install idempotent: '.$packId);
    }
    ppcheck((int)Db::one("SELECT COUNT(*) n FROM tasks WHERE status='done'")['n']===0,'No fabricated completed history');
    ppcheck((int)Db::one('SELECT COUNT(*) n FROM income')['n']===0&&(int)Db::one('SELECT COUNT(*) n FROM expenses')['n']===0,'Packs invent no finances');
    ppcheck(Db::one('PRAGMA integrity_check')['integrity_check']==='ok'&&Db::all('PRAGMA foreign_key_check')===[],'Profile pack integrity');
    ppreject(fn()=>TemplatePackService::install('invalid'),'Unknown pack rejected');
    $db=null;
}finally{$db=null;Db::close(); ProfileManager::close(); ppremove($root);}
echo "$count profile and starter pack checks passed.\n";

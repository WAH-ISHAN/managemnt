<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require ROOT.'/app/Core/Router.php';
require ROOT.'/app/Core/View.php';
$checks=0;
function persona_check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function persona_rejects(callable $fn,string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { persona_check(true,$label); return; } throw new RuntimeException($label); }
function persona_snapshot(): array { return [Db::all('SELECT * FROM settings ORDER BY key'),Db::all('SELECT * FROM local_profiles ORDER BY id'),Db::all('SELECT * FROM modules ORDER BY profile_id,module_id'),Db::all('SELECT * FROM profile_roles ORDER BY profile_id,role')]; }
set_error_handler(static function (int $severity,string $message,string $file,int $line): never { throw new ErrorException($message,0,$severity,$file,$line); });
$directory=sys_get_temp_dir().'/lifeos-persona-'.bin2hex(random_bytes(8));
mkdir($directory.'/Core/migrations',0700,true); $file=$directory.'/lifeos.db';
$oldMigrations=['001_core.sql','002_task_completion_dates.sql'];
try {
    // Start with the former core schema, then apply the actual new migration.
    foreach ($oldMigrations as $migration) copy(ROOT.'/app/Core/migrations/'.$migration,$directory.'/Core/migrations/'.$migration);
    Db::init($file); $modules=Modules::discover(ROOT.'/app/Modules');
    $moduleDirs=array_map(fn($m)=>$m['path'].'/migrations',$modules);
    // A legacy fixture cannot apply a later module data migration before its
    // prerequisite persona tables exist. Load that module's original schema,
    // then apply its current migrations alongside the real core upgrade below.
    $legacyModuleDirs=$moduleDirs;
    foreach ($legacyModuleDirs as $index=>$moduleDir) if (in_array(basename(dirname($moduleDir)),['School','Employee','StudyTools'],true)) {
        $moduleName=basename(dirname($moduleDir));$legacySchool=$directory.'/'.$moduleName.'/migrations'; mkdir($legacySchool,0700,true);
        $initial=match($moduleName){'School'=>'001_school.sql','Employee'=>'001_employee.sql','StudyTools'=>'001_study.sql'};
        copy($moduleDir.'/'.$initial,$legacySchool.'/'.$initial);
        $legacyModuleDirs[$index]=$legacySchool;
    }
    Migrator::run(array_merge([$directory.'/Core/migrations'],$legacyModuleDirs));
    Db::set('name','Disposable existing owner'); Db::set('pin_hash','retained-test-hash');
    $task=PlannerService::saveTask(null,['title'=>'Retained personal task','due'=>'2026-11-03']);
    $income=SalaryService::addIncome('2026-10-30','Entered income','100.00');
    $expense=SalaryService::addExpense('2026-10-30',1,1,'10.25','Entered expense');
    $uni=Db::run("INSERT INTO uni_modules(code,name,credits,semester) VALUES ('TEST','Entered subject',10,'1')");
    $records=[Db::all('SELECT * FROM tasks'),Db::all('SELECT * FROM income'),Db::all('SELECT * FROM expenses'),Db::all('SELECT * FROM accounts'),Db::all('SELECT * FROM uni_modules'),Db::all('SELECT * FROM settings ORDER BY key')];
    $dirs=array_merge([ROOT.'/app/Core/migrations'],$moduleDirs); Migrator::run($dirs); Migrator::run($dirs);
    persona_check($records===[Db::all('SELECT * FROM tasks'),Db::all('SELECT * FROM income'),Db::all('SELECT * FROM expenses'),Db::all('SELECT * FROM accounts'),Db::all('SELECT * FROM uni_modules'),Db::all('SELECT * FROM settings ORDER BY key')],'Persona upgrade preserves prior identity, PIN, tasks, finances and study records exactly');
    persona_check(PersonaService::profile()===null && Db::all('SELECT * FROM modules')===[],'Upgrade does not silently select a persona or seed module configuration');
    persona_check(!OnboardingService::needed(),'Existing records are not forced through new onboarding');
    foreach (PersonaService::catalog() as $module) persona_check(PersonaService::enabled($module['id']),'Existing installation keeps module on before selecting persona: '.$module['id']);
    OnboardingService::finish('Legacy caller','Legacy institute',['study','money'],true);
    persona_check(Db::setting('name')==='Legacy caller' && Db::setting('institution')==='Legacy institute' && Db::setting('notifications_enabled')==='1' && !OnboardingService::needed(),'Old four-argument onboarding API remains compatible');
    persona_check(PersonaService::profile()===null && PersonaService::enabled('Uni'),'Legacy onboarding leaves module behavior unchanged');
    $expected=[
        'employee'=>['Employee','Work','Salary','Investments','Career','Growth','Freedom','Focus','Documents','LifeAdmin','Lessons','LifePlanning','Platform'],
        'uni'=>['Uni','StudyTools','Salary','Focus','Growth','Documents','Lessons','Career','Freedom','LifePlanning','Platform'],
        'freelancer'=>['Work','Salary','Investments','Career','Documents','Growth','Freedom','Websites','Focus','LifePlanning','Platform'],
        'business'=>['Work','Salary','Investments','SmallBusiness','Websites','WebAdmin','Documents','LifeAdmin','Growth','LifePlanning','Platform'],
        'job_seeker'=>['Career','Growth','Documents','Focus','Salary','Freedom','Lessons','LifePlanning','Platform'],
    ];
    $layouts=['employee'=>'work','uni'=>'study','freelancer'=>'work','business'=>'money','job_seeker'=>'career'];
    $required=['Dashboard','Planner','Settings','Onboarding','Insights','Automation'];
    $personalCounts=[]; foreach (['tasks','income','expenses','accounts','uni_modules','planner_habits','focus_sessions','career_applications','automation_rules'] as $table) $personalCounts[$table]=(int)Db::one('SELECT COUNT(*) n FROM '.$table)['n'];
    foreach ($expected as $persona=>$on) {
        $on[]='Intelligence';if(in_array($persona,['employee','freelancer','business'],true))$on[]='WorkRoles';
        PersonaService::apply($persona,'si'); $profile=PersonaService::profile();
        persona_check($profile['persona']===$persona && $profile['locale']==='si' && $profile['dashboard_layout']===$layouts[$persona] && $profile['name']==='Legacy caller','Persona identity, locale and dashboard choice are saved: '.$persona);
        foreach (PersonaService::catalog() as $module) persona_check(PersonaService::enabled($module['id'])===in_array($module['id'],array_merge($required,$on),true),'Relevant modules selected for '.$persona.': '.$module['id']);
        persona_check(count(Db::all('SELECT * FROM local_profiles'))===1 && count(Db::all('SELECT * FROM modules'))===count(PersonaService::catalog()),'Persona switching updates current profile instead of creating duplicates: '.$persona);
    }
    foreach ($personalCounts as $table=>$count) persona_check((int)Db::one('SELECT COUNT(*) n FROM '.$table)['n']===$count,'Persona defaults do not invent or delete personal records: '.$table);
    foreach (['hnd'=>'uni','software_engineer'=>'employee'] as $pack=>$persona) { PersonaService::apply($persona,'ta',$pack); persona_check(PersonaService::profile()['template']===$pack && LocaleService::language()==='ta','Matching starter pack persists: '.$pack); }
    persona_check(!PersonaService::enabled('School') && !class_exists('SchoolService'),'Retired School module cannot load or run');
    persona_check(!in_array('School',array_column(PersonaService::catalog(),'id'),true),'Retired schema is absent from module settings');
    persona_rejects(fn()=>PersonaService::apply('school'),'Removed School role rejected');
    persona_rejects(fn()=>TemplatePackService::install('grade_11'),'Removed Grade 11 starter pack rejected');
    $before=persona_snapshot();
    persona_rejects(fn()=>PersonaService::apply('employee','en','hnd'),'Mismatched starter pack rejected');
    persona_check(persona_snapshot()===$before,'Mismatched pack leaves profile, modules and language unchanged');
    persona_rejects(fn()=>OnboardingService::finish('Must roll back','Must roll back',['career'],false,'school','si','hnd'),'Mismatched pack inside onboarding rejected');
    persona_check(persona_snapshot()===$before,'Failed onboarding rolls back name, preferences, completion state and persona together');
    persona_rejects(fn()=>PersonaService::apply('invented','en'),'Unknown persona rejected');
    persona_rejects(fn()=>PersonaService::apply('employee','xx'),'Unsupported language rejected');
    persona_rejects(fn()=>PersonaService::apply('employee','en','invented'),'Unknown starter pack rejected');
    persona_check(persona_snapshot()===$before,'Rejected persona inputs have no saved effects');
    foreach([
        [['uni'],['Uni','StudyTools','Salary'],['School','Employee','WorkRoles']],
        [['employee'],['Employee','Work','WorkRoles'],['Uni','StudyTools','School']],
        [['uni','employee'],['Uni','StudyTools','Employee','Work','WorkRoles'],['School']],
    ]as [$roles,$enabledRoles,$disabledRoles]) {
        PersonaService::apply($roles,'en');$stored=PersonaService::selectedRoles();$expectedRoles=$roles;sort($expectedRoles);
        persona_check($stored===$expectedRoles,'Every selected role is stored: '.implode('+',$roles));
        foreach($enabledRoles as $id)persona_check(PersonaService::enabled($id),'Matching tool enabled: '.implode('+',$roles).' '.$id);
        foreach($disabledRoles as $id)persona_check(!PersonaService::enabled($id),'Unrelated tool hidden: '.implode('+',$roles).' '.$id);
        if(count($roles)>1)persona_check(PersonaService::profile()['dashboard_layout']==='balanced','Combined roles use a combined dashboard');
    }
    PersonaService::apply('employee','en');$roleSnapshot=persona_snapshot();
    persona_rejects(fn()=>PersonaService::configure(['Uni'],[]),'Manual module payload cannot bypass selected roles');
    foreach([[],['uni','unknown'],[['uni']],['uni',17]]as $badRoles)persona_rejects(fn()=>PersonaService::apply($badRoles,'en'),'Invalid multi-role selection rejected');
    persona_check(persona_snapshot()===$roleSnapshot,'Invalid role choices preserve roles, preferences and module state');
    PersonaService::apply(['employee','uni'],'si','hnd');
    persona_check(PersonaService::profile()['template']==='hnd'&&PersonaService::enabled('Employee'),'Starter setup may match either selected role');
    $savedRoles=PersonaService::selectedRoles();Db::close();Db::init($file);
    persona_check(PersonaService::selectedRoles()===$savedRoles,'Multiple roles survive reopening the database');
    PersonaService::apply(['employee','uni'],'en');
    PersonaService::configure(['Work','Uni'],['Work'=>0,'Uni'=>1,'Dashboard'=>500,'Planner'=>501]);
    persona_check(PersonaService::enabled('Work') && PersonaService::enabled('Uni') && !PersonaService::enabled('Salary') && PersonaService::enabled('Automation'),'Module controls change visible tools and preserve the hidden scheduler');
    foreach (['Dashboard','Planner','Settings','Onboarding','Insights'] as $id) persona_check(PersonaService::enabled($id),'Required core module stays enabled: '.$id);
    $menu=PersonaService::menu(PersonaService::catalog());
    persona_check(!in_array('Automation',array_map(fn($m)=>basename($m['path']),$menu),true),'Backend automation engine is absent from navigation data');
    Db::run("UPDATE modules SET enabled=0 WHERE profile_id='default' AND module_id='Automation'");
    PersonaService::configure(['Work','Uni'],['Work'=>0,'Uni'=>1]);
    persona_check(!PersonaService::enabled('Automation'),'Saving visible module preferences retains a paused backend scheduler');
    persona_check(basename($menu[0]['path'])==='Work' && basename($menu[1]['path'])==='Uni' && !in_array('Salary',array_map(fn($m)=>basename($m['path']),$menu),true),'Saved order and module switches control ordered menu data');
    $before=persona_snapshot();
    persona_rejects(fn()=>PersonaService::configure(['UnknownModule'],[]),'Unknown module cannot be enabled');
    persona_rejects(fn()=>PersonaService::configure(['Work'],['NotInstalled'=>3]),'Unknown sidebar position rejected');
    persona_rejects(fn()=>PersonaService::configure(['Work'],['Work'=>1000]),'Out-of-range sidebar position rejected');
    persona_rejects(fn()=>PersonaService::configure([['Work']],[]),'Nested checkbox payload rejected');
    persona_check(persona_snapshot()===$before,'Invalid module configuration leaves previous controls intact');
    foreach (['/salary','/salary/plan','/expenses','/expenses/123/delete','/investments','/goals','/goals/123','/lessons','/tasks','/tasks/123','/review','/review/history','/why/net-worth'] as $path) persona_check(!PersonaService::routeEnabled($path),'Disabled module route is blocked: '.$path);
    foreach (['/','/planner','/settings/workspace','/welcome','/insights/alerts','/work','/work/project/123','/uni','/uni/calendar'] as $path) persona_check(PersonaService::routeEnabled($path),'Enabled and required module route stays accessible: '.$path);
    PersonaService::configure(['Salary','Investments','Lessons'],[]);
    foreach (['/expenses','/goals','/tasks','/review','/why/net-worth'] as $path) persona_check(PersonaService::routeEnabled($path),'Legacy alias reopens with module: '.$path);
    PersonaService::apply('uni','si','hnd');
    persona_check(LocaleService::language()==='si' && t('Money')==='මුදල්' && t('Untranslated test key')==='Untranslated test key','Sinhala translations resolve with readable English fallback');
    Db::set('locale','ta'); persona_check(t('Money')==='பணம்','Tamil navigation translation resolves');
    Db::set('locale','invalid'); persona_check(LocaleService::language()==='en' && t('Money')==='Money','Invalid stored language falls back to English');
    Db::set('locale','si'); PersonaService::apply(['uni','employee'],'si'); PersonaService::configure(['Work','Uni'],['Work'=>0,'Uni'=>1]); $saved=persona_snapshot();
    Db::close(); Db::init($file); Migrator::run($dirs);
    persona_check(persona_snapshot()===$saved && PersonaService::enabled('Work') && !PersonaService::enabled('Salary'),'Persona, module switches, language and ordering persist across restart');
    persona_check(Db::one('SELECT title FROM tasks WHERE id=?',[$task])['title']==='Retained personal task' && (float)Db::one('SELECT amount FROM expenses WHERE id=?',[$expense])['amount']===10.25,'Disabled tools retain their records across restart');
    persona_check(Db::one('PRAGMA integrity_check')['integrity_check']==='ok' && Db::all('PRAGMA foreign_key_check')===[],'Persona database preserves integrity and references');
    $upgrade=new PDO('sqlite::memory:');$upgrade->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $upgrade->exec(file_get_contents(ROOT.'/app/Core/migrations/003_persona_modules.sql'));
    foreach(['employee','uni','school','custom']as $oldRole)$upgrade->exec("INSERT INTO local_profiles(id,name,persona) VALUES ('$oldRole','Preserved name','$oldRole')");
    $upgrade->exec("INSERT INTO modules(profile_id,module_id,enabled,sort_order) VALUES ('employee','Employee',1,17)");
    $priorProfiles=$upgrade->query('SELECT * FROM local_profiles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $priorModules=$upgrade->query('SELECT * FROM modules')->fetchAll(PDO::FETCH_ASSOC);
    $upgrade->exec(file_get_contents(ROOT.'/app/Core/migrations/007_workspace_roles.sql'));
    persona_check($upgrade->query('SELECT role FROM profile_roles ORDER BY role')->fetchAll(PDO::FETCH_COLUMN)===['employee','school','uni'],'Role migration carries existing single selections forward and leaves custom installations unclassified');
    persona_check($priorProfiles===$upgrade->query('SELECT * FROM local_profiles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)&&$priorModules===$upgrade->query('SELECT * FROM modules')->fetchAll(PDO::FETCH_ASSOC),'Role migration preserves saved profile fields and module choices exactly');
    $upgrade->exec("CREATE TABLE school_records(id INTEGER PRIMARY KEY,data TEXT); INSERT INTO school_records(data) VALUES ('preserved homework'); INSERT INTO modules(profile_id,module_id,enabled,sort_order) VALUES ('school','School',1,24)");
    $upgrade->exec(file_get_contents(ROOT.'/app/Core/migrations/008_retire_school.sql'));
    persona_check($upgrade->query("SELECT COUNT(*) FROM profile_roles WHERE role='school'")->fetchColumn()==0 && $upgrade->query("SELECT persona FROM local_profiles WHERE id='school'")->fetchColumn()==='custom','School-only profiles retire to a valid generic workspace');
    persona_check($upgrade->query("SELECT enabled FROM modules WHERE module_id='School'")->fetchColumn()==0 && $upgrade->query('SELECT data FROM school_records')->fetchColumn()==='preserved homework','Retirement disables School without deleting existing records');
    persona_check($upgrade->query("SELECT enabled FROM modules WHERE module_id='Employee'")->fetchColumn()==1,'Retirement preserves unrelated module preferences');
} finally {
    Db::close(); foreach ([$file,$file.'-wal',$file.'-shm'] as $temporary) if (is_file($temporary)) unlink($temporary);
    foreach ($oldMigrations as $migration) if (is_file($directory.'/Core/migrations/'.$migration)) unlink($directory.'/Core/migrations/'.$migration);
    foreach(['School','Employee','StudyTools']as $moduleName)if (is_dir($directory.'/'.$moduleName.'/migrations')) { foreach (glob($directory.'/'.$moduleName.'/migrations/*') ?: [] as $temporary) unlink($temporary); rmdir($directory.'/'.$moduleName.'/migrations'); rmdir($directory.'/'.$moduleName); }
    rmdir($directory.'/Core/migrations'); rmdir($directory.'/Core'); rmdir($directory);
    restore_error_handler();
}
echo "$checks persona checks passed.\n";

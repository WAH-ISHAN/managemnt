<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
Db::init(':memory:');
Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules'))));
$checks=0;
function lc(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$catalogs=[];
foreach([EmployeeService::class,StudyService::class,LifePlanningService::class] as $service){if(method_exists($service,'catalog'))$catalogs[$service]=$service::catalog();}
foreach(['en','si','ta'] as $language){
    Db::set('locale',$language);
    foreach($catalogs as $service=>$catalog)lc($service::catalog()===$catalog,'Catalog types and persisted values stay invariant: '.$service.' '.$language);
    lc(ui_date('Y-m-d',1735689600)==='2025-01-01','ISO form dates stay unchanged');
    lc(ui_markup('<input name="title" value="Save"><strong>Save</strong>')==='<input name="title" value="Save"><strong>'.e(t('Save')).'</strong>','Literal markup changes only its display text');
    lc(e('Save')==='Save','User data is never implicitly translated');
    lc(t('Save')!=='' && ($language==='en' || t('Save')!=='Save'),'Save action translated');
    lc(t('RESTORE')==='RESTORE','Exact restore confirmation token is preserved');
}
Db::set('locale','si');$before=t('Money');
try{Db::tx(function(){Db::set('locale','ta');lc(t('Money')==='பணம்','Setting cache updates within transaction');throw new RuntimeException('rollback');});}catch(RuntimeException $e){if($e->getMessage()!=='rollback')throw $e;}
lc(t('Money')===$before,'Setting cache resets after rollback');
Db::run("UPDATE settings SET value='en' WHERE key='locale'");lc(t('Money')==='Money','Direct writes invalidate cached settings');
$pairs=[];
foreach(glob(ROOT.'/app/Modules/Onboarding/locales/*.php') as $file)$pairs=array_replace($pairs,require $file);
foreach($pairs as $key=>$pair)lc(count($pair)===2&&is_string($pair[0])&&is_string($pair[1])&&trim($pair[0])!==''&&trim($pair[1])!==''&&mb_check_encoding($pair[0],'UTF-8')&&mb_check_encoding($pair[1],'UTF-8'),'Valid locale pair: '.$key);
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT.'/app'))as $file){if(!$file->isFile()||!str_contains(str_replace('\\','/',$file->getPathname()),'/views/')||$file->getExtension()!=='php')continue;preg_match_all('~<option((?:<\?[\s\S]*?\?>|[^>])*?)>([\s\S]*?)</option>~',file_get_contents($file->getPathname()),$options,PREG_SET_ORDER);foreach($options as $option)if(str_contains($option[2],'t('))lc((bool)preg_match('/\bvalue\s*=/',$option[1]),'Translated option keeps an explicit persisted value: '.$file->getPathname());}
echo $checks." localization checks passed.\n";

<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
foreach (['Modules','Router','View','helpers'] as $file) require ROOT.'/app/Core/'.$file.'.php';
$checks=0;
function ml_check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$modules=Modules::discover(ROOT.'/app/Modules');
Modules::register($modules,new Router());
ml_check(!class_exists('WorkIssueService',false),'Registering routes does not parse unused Work issue services');
ml_check(class_exists('WorkIssueService'),'A requested service autoloads from an enabled module');
ml_check(class_exists('WorkIssueService',false),'Requested service is now loaded');
ml_check(class_exists('workreleaseservice'),'Service lookup retains PHP case-insensitive class names');
ml_check(class_exists('WebsiteHubTransport'),'An explicit manifest alias loads a nonmatching filename');
ml_check(!class_exists('MissingLifeOSService'),'Unknown service names do not load arbitrary files');
$fixture=sys_get_temp_dir().'/lifeos-module-loading-'.bin2hex(random_bytes(8));
mkdir($fixture.'/src',0700,true);
try {
    file_put_contents($fixture.'/src/LazyFixture.php','<?php final class LazyFixture { public const VALUE="fixture"; }');
    $module=['path'=>$fixture];
    Modules::register([$module],new Router());
    ml_check(!class_exists('LazyFixture',false),'A new module service remains unloaded until referenced');
    Modules::register([],new Router());
    ml_check(!class_exists('LazyFixture'),'Unavailable modules are removed from the lazy service map');
    Modules::register([$module],new Router());
    ml_check(LazyFixture::VALUE==='fixture','A reenabled module loads its service on first reference');
    foreach ([['EscapingService'=>'../outside.php'],['Malformed-Class'=>'LazyFixture.php'],'not-a-map'] as $aliases) {
        try { Modules::register([array_merge($module,['autoload'=>$aliases])],new Router()); throw new LogicException('Malformed service map accepted'); }
        catch (RuntimeException) { ml_check(true,'Malformed or escaping module service map is rejected'); }
    }
    echo "$checks module service loading checks passed.\n";
} finally {
    unlink($fixture.'/src/LazyFixture.php');rmdir($fixture.'/src');rmdir($fixture);
}

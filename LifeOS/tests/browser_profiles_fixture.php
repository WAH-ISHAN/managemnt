<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require ROOT.'/app/Core/ProfileManager.php';
$data=$argv[1] ?? '';
if (!is_dir($data) || is_file($data.'/lifeos.db')) throw new RuntimeException('A fresh disposable directory is required.');
Db::init($data.'/lifeos.db');
Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules'))));
ProfileManager::init($data);
Db::set('name','Browser QA adult'); Db::set('onboarding_complete','1'); Db::set('pin_hash',password_hash('1234',PASSWORD_DEFAULT));
$child=ProfileManager::create('Browser QA child','5678');
Db::init(ProfileManager::directory($child).'/lifeos.db');Db::set('onboarding_complete','1');
file_put_contents($data.'/browser-fixture.json',json_encode(['child'=>$child],JSON_THROW_ON_ERROR));
echo "Disposable browser profiles created.\n";

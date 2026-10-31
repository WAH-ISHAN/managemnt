<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function fw_check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$directory=sys_get_temp_dir().'/lifeos-fresh-workspace-'.bin2hex(random_bytes(8));
mkdir($directory,0700,true);$file=$directory.'/lifeos.db';
try {
    Db::init($file);$modules=Modules::discover(ROOT.'/app/Modules');
    $dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules));
    Migrator::run($dirs);
    fw_check(OnboardingService::needed(),'A brand-new database needs onboarding');
    foreach (Db::all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table) {
        $name=$table['name'];
        if (in_array($name,['migrations','settings','accounts','allocation_rules','expense_categories'],true)) continue;
        fw_check((int)Db::one('SELECT COUNT(*) n FROM "'.str_replace('"','""',$name).'"')['n']===0,'Fresh personal table starts empty: '.$name);
    }
    fw_check((float)Db::one('SELECT SUM(balance) n FROM accounts')['n']===0.0,'Default account balances are zero');
    fw_check((float)Db::one('SELECT SUM(monthly_budget) n FROM expense_categories')['n']===0.0,'Default budgets are zero');
    fw_check(!UniCalendarService::config()['enabled']&&UniCalendarService::config()['batchId']==='','Fresh calendar has no fabricated batch or connection');
    OnboardingService::finish('Disposable new owner','Disposable institution',['study','money'],false);
    $task=PlannerService::saveTask(null,['title'=>'First personally entered task','due'=>'2026-11-01']);
    $income=SalaryService::addIncome('2026-10-30','Personally entered income','100.00');
    $expense=SalaryService::addExpense('2026-10-30',1,1,'10.25','Personally entered expense');
    Db::close();Db::init($file);Migrator::run($dirs);
    fw_check(!OnboardingService::needed()&&Db::setting('name')==='Disposable new owner','Workspace identity persists after reopening');
    fw_check(Db::one('SELECT title FROM tasks WHERE id=?',[$task])['title']==='First personally entered task','First task persists after reopening');
    fw_check((float)Db::one('SELECT amount FROM income WHERE id=?',[$income])['amount']===100.0,'First income persists after reopening');
    fw_check((float)Db::one('SELECT amount FROM expenses WHERE id=?',[$expense])['amount']===10.25,'First expense persists after reopening');
    fw_check((int)round((float)Db::one('SELECT SUM(balance) n FROM accounts')['n']*100)===8975,'First financial entries preserve the exact remaining account total');
    fw_check(Db::one('PRAGMA integrity_check')['integrity_check']==='ok'&&Db::all('PRAGMA foreign_key_check')===[],'Fresh workspace passes database and reference integrity');
    echo "$checks fresh workspace checks passed.\n";
} finally {
    Db::close();
    foreach ([$file,$file.'-shm',$file.'-wal'] as $path) if (is_file($path)) unlink($path);
    rmdir($directory);
}

<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require ROOT.'/app/Core/Router.php';
require ROOT.'/app/Core/View.php';
$checks=0;
function modern_check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function modern_rejects(callable $fn,string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { modern_check(true,$label); return; } throw new RuntimeException($label); }
function modern_request(Router $router,string $method,string $path,array $data=[]): string {
    $_POST=$data; $_SERVER['REQUEST_URI']=$path;
    ob_start(); try { $router->dispatch($method,$path); return ob_get_clean(); } catch (Throwable $e) { ob_end_clean(); throw $e; }
}
set_error_handler(static function (int $severity,string $message,string $file,int $line): never { throw new ErrorException($message,0,$severity,$file,$line); });
$_SESSION=['csrf'=>'test-token']; $_SERVER['HTTP_HX_REQUEST']='true';
$file=sys_get_temp_dir().'/lifeos-modern-'.bin2hex(random_bytes(6)).'.db';
try {
    Db::init($file); $modules=Modules::discover(ROOT.'/app/Modules');
    $dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($module)=>$module['path'].'/migrations',$modules));
    Migrator::run($dirs); $migrations=count(Db::all('SELECT * FROM migrations')); Migrator::run($dirs);
    modern_check(count(Db::all('SELECT * FROM migrations'))===$migrations,'Migrations apply once and remain repeatable');
    $router=new Router(); foreach (['LifeAdmin','SmallBusiness','Dashboard'] as $module) (require ROOT.'/app/Modules/'.$module.'/routes.php')($router);
    $accounts=Db::all('SELECT * FROM accounts ORDER BY id');
    modern_check(str_contains(modern_request($router,'GET','/life-admin'),'No lending or borrowing saved yet.'),'Fresh personal essentials renders without fabricated records');
    modern_check(str_contains(modern_request($router,'GET','/small-business'),'No projects yet.'),'Fresh business workspace renders empty');
    modern_request($router,'POST','/life-admin/expiry',['title'=>'Passport <sample>','kind'=>'Document','expires_on'=>'2027-01-01','remind_days'=>'30','notes'=>'Stored safely <script>']);
    modern_request($router,'POST','/life-admin/subscription',['name'=>'Course library','amount'=>'12000','cadence'=>'yearly','renews_on'=>'2027-02-01','notes'=>'Annual subscription note']);
    modern_request($router,'POST','/life-admin/loan',['person'=>'Friend','direction'=>'lent','amount'=>'1000.30','due_on'=>'2026-01-01','notes'=>'Loan note visible']);
    modern_request($router,'POST','/life-admin/loan/1/payment',['amount'=>'200.10','paid_on'=>'2026-10-29']);
    $loanPage=modern_request($router,'GET','/life-admin');
    modern_check(str_contains($loanPage,'Rs. 800.20 remaining'),'Partial repayments reduce the displayed loan balance exactly');
    modern_check(str_contains($loanPage,'Payment history') && str_contains($loanPage,'Rs. 200.10'),'Payment amounts and history remain reviewable');
    modern_check(str_contains($loanPage,'Active monthly equivalent: Rs. 1,000.00'),'Yearly subscriptions contribute one twelfth of their entered cost');
    modern_check(str_contains($loanPage,'Passport &lt;sample&gt;') && str_contains($loanPage,'&lt;script&gt;') && !str_contains($loanPage,'<script>'),'User-entered titles and notes render as text');
    modern_check(str_contains($loanPage,'Loan note visible') && str_contains($loanPage,'Annual subscription note'),'Saved notes remain visible');
    modern_rejects(fn()=>modern_request($router,'POST','/life-admin/loan/1/payment',['amount'=>'800.21','paid_on'=>'2026-10-29']),'Repayments above the remaining amount are rejected');
    modern_check(count(Db::all('SELECT * FROM life_loan_payments'))===1,'Rejected repayment leaves existing records intact');
    modern_rejects(fn()=>modern_request($router,'POST','/life-admin/loan/999/payment',['amount'=>'1','paid_on'=>'2026-10-29']),'Nonexistent loans reject payments');
    modern_request($router,'POST','/life-admin/loan/2/payment/1/delete');
    modern_check(count(Db::all('SELECT * FROM life_loan_payments'))===1,'Payment removal is scoped to its own loan');
    modern_request($router,'POST','/life-admin/loan/1/payment/1/delete');
    modern_check(str_contains(modern_request($router,'GET','/life-admin'),'Rs. 1,000.30 remaining'),'Correcting a mistaken repayment restores the balance');
    modern_request($router,'POST','/life-admin/expiry/1/done');
    modern_request($router,'POST','/life-admin/expiry/1/renew',['expires_on'=>'2028-01-01']);
    modern_check(Db::one('SELECT * FROM life_expiries WHERE id=1')['expires_on']==='2028-01-01' && Db::one('SELECT * FROM life_expiries WHERE id=1')['status']==='active','Renewing an expiry saves the exact entered date and reactivates reminders');
    modern_rejects(fn()=>modern_request($router,'POST','/life-admin/subscription/1/renew',['renews_on'=>'2027-02-30']),'Invalid renewal dates are rejected');
    modern_request($router,'POST','/life-admin/subscription/1/cancel');
    modern_check(str_contains(modern_request($router,'GET','/life-admin'),'Active monthly equivalent: Rs. 0.00'),'Cancelled subscriptions are excluded from the active monthly total');
    modern_request($router,'POST','/life-admin/subscription/1/renew',['renews_on'=>'2028-02-01']);
    modern_check(Db::one('SELECT * FROM life_subscriptions WHERE id=1')['renews_on']==='2028-02-01','Manual subscription renewal saves the entered date');
    modern_request($router,'POST','/small-business/project',['name'=>'Website','client'=>'Client <one>','hourly_rate'=>'2500','notes'=>'Project note visible']);
    foreach ([['time','2.50'],['income','12500'],['cost','2500.25']] as [$kind,$quantity]) modern_request($router,'POST','/small-business/project/1/entry',['date'=>'2026-10-29','kind'=>$kind,'quantity'=>$quantity,'note'=>'Entered '.$kind]);
    $business=modern_request($router,'GET','/small-business');
    modern_check(str_contains($business,'Profit Rs. 9,999.75') && str_contains($business,'2.50 hours'),'Project profit uses recorded income minus cost and time remains separate');
    $project=modern_request($router,'GET','/small-business/project/1');
    modern_check(str_contains($project,'Project note visible') && str_contains($project,'Client &lt;one&gt;'),'Project notes and escaped client name render');
    modern_request($router,'POST','/small-business/project/2/entry/3/delete');
    modern_check(count(Db::all('SELECT * FROM business_entries'))===3,'Business record removal is scoped to its own project');
    modern_request($router,'POST','/small-business/project/1/entry/3/delete');
    modern_check(str_contains(modern_request($router,'GET','/small-business'),'Profit Rs. 12,500.00'),'Removing a mistaken cost recalculates project profit');
    modern_request($router,'POST','/small-business/project/1/close');
    modern_rejects(fn()=>modern_request($router,'POST','/small-business/project/1/entry',['date'=>'2026-10-29','kind'=>'income','quantity'=>'10','note'=>'']),'Closed projects reject new entries');
    modern_check(Db::all('SELECT * FROM accounts ORDER BY id')===$accounts,'Personal ledger and business tracking do not change account balances');
    modern_check(count(Db::all('SELECT * FROM income'))===0 && count(Db::all('SELECT * FROM expenses'))===0,'Manual renewals do not create charges or income');
    Db::run('UPDATE accounts SET balance=5000 WHERE id=2');
    $asset=InvestmentService::create('Recorded investment','cash','2026-01-01',''); InvestmentService::entry($asset,'2026-01-01','deposit','1000','');
    $why=modern_request($router,'GET','/why/net-worth');
    modern_check(str_contains($why,'Recorded investment') && str_contains($why,'Rs. 6,000.00'),'Net-worth explanation renders entered accounts and assets with their total');
    Db::close(); Db::init($file); Migrator::run($dirs);
    modern_check(count(Db::all('SELECT * FROM life_loans'))===1 && count(Db::all('SELECT * FROM business_entries'))===2,'Records persist across database reopen and repeat migrations');
    Db::close();
} finally {
    Db::close(); foreach ([$file,$file.'-wal',$file.'-shm'] as $temporary) if (is_file($temporary)) unlink($temporary);
    restore_error_handler();
}
echo "$checks modern tools checks passed.\n";

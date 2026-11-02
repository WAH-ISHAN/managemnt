<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function alert_check(bool $condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function alert_rejects(callable $action,string $label): void { try { $action(); } catch (InvalidArgumentException) { alert_check(true,$label); return; } throw new RuntimeException($label); }
$file=sys_get_temp_dir().'/lifeos-alert-import-'.bin2hex(random_bytes(6)).'.db';
try {
    Db::init($file);
    $modules=Modules::discover(ROOT.'/app/Modules');
    Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    $categories=array_column(Db::all('SELECT id,name FROM expense_categories'),'id','name');
    $account=(int)Db::one("SELECT id FROM accounts WHERE name='Needs'")['id'];
    $alert='Your card was debited LKR 1,250.00 on 02/11/2026 at Market. Available balance LKR 9,999.00';
    $preview=ExpenseAlertImport::preview($alert);
    alert_check($preview['amount']==='1250.00' && $preview['date']==='2026-11-02' && !$preview['assumedDate'],'Debit amount and explicit local date are parsed without taking the balance');
    alert_check(!$preview['duplicate'] && Db::all('SELECT * FROM expenses')===[],'Preview never posts an expense');
    alert_check(ExpenseAlertImport::fingerprint("  YOUR card was debited LKR 1,250.00 on 02/11/2026 at Market.  Available balance LKR 9,999.00 ")===ExpenseAlertImport::fingerprint($alert),'Repeated whitespace and case keep one duplicate identity');
    $id=ExpenseAlertImport::post($alert,$preview['date'],$preview['amount'],(int)$categories['Food & groceries'],$account,'Market');
    alert_check($id>0 && ExpenseAlertImport::duplicate($alert),'Explicit posting records the import fingerprint');
    alert_check((float)Db::one('SELECT balance FROM accounts WHERE id=?',[$account])['balance']===-1250.0,'One import debits the selected account once');
    alert_rejects(fn()=>ExpenseAlertImport::post($alert,$preview['date'],$preview['amount'],(int)$categories['Food & groceries'],$account,'Market'),'Duplicate alert cannot debit again');
    alert_check(count(Db::all('SELECT * FROM expenses'))===1 && (float)Db::one('SELECT balance FROM accounts WHERE id=?',[$account])['balance']===-1250.0,'Duplicate rejection leaves records and balance unchanged');
    alert_rejects(fn()=>ExpenseAlertImport::preview('Available balance LKR 1000.00'),'Balance-only message is not treated as an expense');
    alert_rejects(fn()=>ExpenseAlertImport::preview('Card debited LKR 20.00 and payment LKR 30.00'),'Ambiguous multiple debit amounts are rejected');
    alert_check(ExpenseAlertImport::preview('Payment Rs. 125.00')['assumedDate']===true,'Missing date is marked as an assumption for review');
    SalaryService::deleteExpense($id);
    alert_check(!ExpenseAlertImport::duplicate($alert),'Deleting an imported expense clears its fingerprint for correction');
    Db::close();
    echo "$checks expense alert import checks passed.\n";
} finally {
    Db::close();
    foreach ([$file,$file.'-wal',$file.'-shm'] as $path) if (is_file($path)) unlink($path);
}

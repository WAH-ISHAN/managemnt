<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function cost_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message);$checks++; }
function cost_rejects(callable $fn,string $message): void { try {$fn();} catch (InvalidArgumentException|PDOException) {cost_check(true,$message);return;} throw new RuntimeException($message); }
$path=sys_get_temp_dir().'/lifeos-costs-'.bin2hex(random_bytes(8)).'.sqlite';
$_SESSION=[];
try {
    Db::init($path);$modules=Modules::discover(ROOT.'/app/Modules');
    Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($module)=>$module['path'].'/migrations',$modules)));
    $site=WebsiteService::save(null,['name'=>'Cost test site','business_name'=>'Business A','base_url'=>'https://example.com']);
    $other=WebsiteService::save(null,['name'=>'Other site','base_url'=>'https://other.example.com']);
    cost_check(WebsiteCostService::summaries()['count']===0,'New websites have no invented cost records');
    Db::run('UPDATE accounts SET balance=2000 WHERE id=3');
    $category=(int)Db::one("SELECT id FROM expense_categories WHERE name='Bills'")['id'];
    $values=['date'=>date('Y-m-d'),'kind'=>'hosting','amount'=>'1200.50','vendor'=>'Hosting provider','notes'=>'Private cost notes'];
    $cost=WebsiteCostService::add($site,$values);
    cost_check((float)Db::one('SELECT balance FROM accounts WHERE id=3')['balance']===2000.0 && Db::one('SELECT COUNT(*) n FROM expenses')['n']===0,'Website-only costs do not debit a Money account');
    $linked=WebsiteCostService::add($site,array_merge($values,['kind'=>'maintenance','amount'=>'250.25','account_id'=>'3','category_id'=>(string)$category]));
    $entry=Db::one('SELECT * FROM website_costs WHERE id=?',[$linked]);$expense=(int)$entry['expense_id'];
    cost_check((float)Db::one('SELECT balance FROM accounts WHERE id=3')['balance']===1749.75,'Linked cost debits the chosen account once');
    cost_check(Db::one('SELECT COUNT(*) n FROM expenses')['n']===1 && $entry['money_recorded']===1,'Linked cost creates exactly one Money expense');
    cost_check(str_contains(Db::one('SELECT note FROM expenses WHERE id=?',[$expense])['note'],'Cost test site'),'Money expense identifies its website');
    $past=(new DateTimeImmutable('first day of last month'))->format('Y-m-d');
    $old=WebsiteCostService::add($site,array_merge($values,['date'=>$past,'kind'=>'domain','amount'=>'499.99']));
    $summary=WebsiteCostService::summaries();
    cost_check((int)round($summary['sites'][$site]['total']*100)===195074 && (int)round($summary['sites'][$site]['month_total']*100)===145075,'Monthly and all-time totals use the recorded dates and exact cents');
    cost_check(!isset($summary['sites'][$other]),'Costs stay scoped to their website');
    cost_check(count(WebsiteCostService::records($site,date('Y-m')))===2 && count(WebsiteCostService::records($site,null))===3,'Month filtering and all-date history work independently');
    cost_rejects(fn()=>WebsiteCostService::remove($other,$linked),'Another website cannot remove this cost');
    cost_rejects(fn()=>WebsiteService::remove($site),'Profiles with cost history are protected from deletion');
    SalaryService::deleteExpense($expense);
    $entry=Db::one('SELECT * FROM website_costs WHERE id=?',[$linked]);
    cost_check($entry['expense_id']===null && $entry['money_recorded']===1,'Deleting Money expense retains website-only history');
    WebsiteCostService::remove($site,$linked);
    cost_check((float)Db::one('SELECT balance FROM accounts WHERE id=3')['balance']===2000.0,'Removing an already-unlinked cost does not credit the account twice');
    Db::exec("CREATE TRIGGER fail_cost_insert BEFORE INSERT ON website_costs BEGIN SELECT RAISE(ABORT,'test insert failure'); END");
    cost_rejects(fn()=>WebsiteCostService::add($site,array_merge($values,['amount'=>'10.01','account_id'=>'3','category_id'=>(string)$category])),'A failed cost write rejects the linked transaction');
    cost_check(Db::one('SELECT COUNT(*) n FROM expenses')['n']===0 && (float)Db::one('SELECT balance FROM accounts WHERE id=3')['balance']===2000.0,'Failed cost write rolls back Money expense and debit');
    Db::exec('DROP TRIGGER fail_cost_insert');
    $linked=WebsiteCostService::add($site,array_merge($values,['amount'=>'99.99','account_id'=>'3','category_id'=>(string)$category]));
    $expense=(int)Db::one('SELECT expense_id FROM website_costs WHERE id=?',[$linked])['expense_id'];
    Db::exec("CREATE TRIGGER fail_cost_delete BEFORE DELETE ON website_costs BEGIN SELECT RAISE(ABORT,'test delete failure'); END");
    cost_rejects(fn()=>WebsiteCostService::remove($site,$linked),'A failed linked-cost removal rejects the transaction');
    cost_check((int)Db::one('SELECT expense_id FROM website_costs WHERE id=?',[$linked])['expense_id']===$expense && Db::one('SELECT id FROM expenses WHERE id=?',[$expense])!==null && (float)Db::one('SELECT balance FROM accounts WHERE id=3')['balance']===1900.01,'Failed removal restores the cost link, expense and balance');
    Db::exec('DROP TRIGGER fail_cost_delete');
    WebsiteCostService::remove($site,$linked);
    cost_check(Db::one('SELECT id FROM website_costs WHERE id=?',[$linked])===null && Db::one('SELECT id FROM expenses WHERE id=?',[$expense])===null && (float)Db::one('SELECT balance FROM accounts WHERE id=3')['balance']===2000.0,'Removing a linked cost reverses the account and deletes both rows atomically');
    foreach (['0','-1','1.001'] as $invalid) cost_rejects(fn()=>WebsiteCostService::add($site,array_merge($values,['amount'=>$invalid])),'Invalid monetary precision or amount rejected');
    cost_rejects(fn()=>WebsiteCostService::add($site,array_merge($values,['date'=>'2026-02-30'])),'Invalid calendar dates rejected');
    cost_rejects(fn()=>WebsiteCostService::add($site,array_merge($values,['kind'=>'unknown'])),'Unknown cost types rejected');
    cost_rejects(fn()=>WebsiteCostService::add($site,array_merge($values,['account_id'=>'99999','category_id'=>(string)$category])),'Unknown Money accounts rejected without a cost record');
    cost_check(WebsiteCostService::summaries()['count']===2,'Rejected operations leave cost history unchanged');
    WebsiteCostService::remove($site,$cost);WebsiteCostService::remove($site,$old);WebsiteService::remove($site);
    cost_check(WebsiteCostService::summaries()['count']===0,'Resolved cost history permits profile removal');
    echo $checks." website maintenance cost checks passed.\n";
} finally {
    Db::close();$_SESSION=[];
    foreach ([$path,$path.'-wal',$path.'-shm'] as $file) if(is_file($file))unlink($file);
}

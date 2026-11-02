<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function expense_rule_check(bool $condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function expense_rule_rejects(callable $action,string $label): void { try { $action(); } catch (InvalidArgumentException) { expense_rule_check(true,$label); return; } throw new RuntimeException($label); }
$file=sys_get_temp_dir().'/lifeos-expense-rules-'.bin2hex(random_bytes(6)).'.db';
try {
    Db::init($file);
    $modules=Modules::discover(ROOT.'/app/Modules');
    Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    $categories=Db::all('SELECT id,name FROM expense_categories');
    $category=array_column($categories,'id','name');
    expense_rule_check(ExpenseCategoryRules::all()===[] && ExpenseCategoryRules::suggest('BUS FARE')===null,'Fresh workspace has no expense category assumptions');
    $transport=ExpenseCategoryRules::save(null,'  Bus   fare  ',(int)$category['Transport'],20,true);
    $food=ExpenseCategoryRules::save(null,'bus fare snack',(int)$category['Food & groceries'],10,true);
    expense_rule_check(ExpenseCategoryRules::suggest('Paid BUS fare today')['category']==='Transport','Case-insensitive whole phrase matches');
    expense_rule_check(ExpenseCategoryRules::suggest('My business fare')===null,'Word boundary avoids substring matches');
    expense_rule_check(ExpenseCategoryRules::suggest('Bus fare snack')['category']==='Food & groceries','Lower priority number wins when both phrases match');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'BUS fare',(int)$category['Transport'],5,true),'Duplicate keyword is rejected without case sensitivity');
    ExpenseCategoryRules::save($transport,'Bus fare',(int)$category['Transport'],5,false);
    expense_rule_check(ExpenseCategoryRules::suggest('BUS FARE')===null,'Paused rule is ignored');
    expense_rule_check(ExpenseCategoryRules::rule($transport)['enabled']===0,'Rule changes persist');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'a',(int)$category['Transport'],1,true),'One-character keyword is rejected');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'fare',999999,1,true),'Missing category is rejected');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'fare',(int)$category['Transport'],-1,true),'Priority is bounded');
    expense_rule_check(Db::all('SELECT * FROM expenses')===[],'Suggestions never create an expense');
    $account=(int)Db::one("SELECT id FROM accounts WHERE name='Needs'")['id'];
    $before=(float)Db::one('SELECT balance FROM accounts WHERE id=?',[$account])['balance'];
    SalaryService::addExpense('2026-11-02',(int)$category['Food & groceries'],$account,'15.25','Bus fare snack');
    expense_rule_check((float)Db::one('SELECT balance FROM accounts WHERE id=?',[$account])['balance']===$before-15.25,'Only explicit expense save debits the account');
    ExpenseCategoryRules::remove($transport);
    expense_rule_check(count(ExpenseCategoryRules::all())===1 && count(Db::all('SELECT * FROM expenses'))===1,'Deleting a rule preserves posted expenses');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::remove($transport),'Removed rule cannot be deleted twice');
    Db::close();
    echo "$checks expense category rule checks passed.\n";
} finally {
    Db::close();
    foreach ([$file,$file.'-wal',$file.'-shm'] as $path) if (is_file($path)) unlink($path);
}

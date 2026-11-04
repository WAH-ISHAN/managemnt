<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function expense_rule_check(bool $condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function expense_rule_rejects(callable $action,string $label): void { try { $action(); } catch (InvalidArgumentException) { expense_rule_check(true,$label); return; } throw new RuntimeException($label); }
function expense_rule_finances(): array { $result=[]; foreach (['accounts','expenses','income','allocations','assets','asset_transactions','asset_metrics'] as $table) { if (Db::one("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",[$table])) $result[$table]=Db::all('SELECT * FROM '.$table.' ORDER BY id'); } return $result; }
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
    $finances=expense_rule_finances();
    $savedRules=ExpenseCategoryRules::all();
    foreach (["\xc3\x28", "bad\x00text", "bad\x1btext"] as $bad) {
        expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,$bad,(int)$category['Transport'],5,true),'Invalid UTF-8 or controls rejected on save');
        expense_rule_rejects(fn()=>ExpenseCategoryRules::suggest($bad),'Invalid UTF-8 or controls rejected on preview');
    }
    foreach (['', 'x',str_repeat('x',81)] as $bad) expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,$bad,(int)$category['Transport'],5,true),'Keyword length enforced');
    foreach ([1000,-1] as $priority) expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'invalid priority',(int)$category['Transport'],$priority,true),'Out-of-range priority rejected');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'valid keyword',0,1,true),'Zero category rejected');
    expense_rule_check(ExpenseCategoryRules::all()===$savedRules,'Rejected writes preserve all rule records');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save($transport,'BUS fare snack',(int)$category['Transport'],5,true),'Editing into another rule keyword rejected');
    expense_rule_check(ExpenseCategoryRules::all()===$savedRules,'Duplicate edit is atomic');
    ExpenseCategoryRules::save($transport,'Bus fare',(int)$category['Transport'],20,true);
    ExpenseCategoryRules::save($food,'Bus fare snack',(int)$category['Food & groceries'],20,true);
    expense_rule_check(ExpenseCategoryRules::suggest('bus fare snack')['rule_id']===$transport,'Equal priorities resolve in stable creation order');
    ExpenseCategoryRules::save($food,'Bus fare snack',(int)$category['Food & groceries'],19,true);
    expense_rule_check(ExpenseCategoryRules::suggest('bus fare snack')['rule_id']===$food,'Edited priority deterministically changes overlap winner');
    ExpenseCategoryRules::save($transport,'Bus fare',(int)$category['Transport'],5,false);
    $sinhala=ExpenseCategoryRules::save(null,'කඩ',(int)$category['Food & groceries'],100,true);
    expense_rule_check(ExpenseCategoryRules::suggest('කඩ ගෙවීම')['rule_id']===$sinhala,'Sinhala independent word matches');
    expense_rule_check(ExpenseCategoryRules::suggest('කඩේ ගෙවීම')===null,'Sinhala combining vowel sign is not a word boundary');
    expense_rule_check(ExpenseCategoryRules::suggest('අකඩ')===null&&ExpenseCategoryRules::suggest('කඩ2')===null,'Sinhala letters and digits prevent substring matches');
    $tamil=ExpenseCategoryRules::save(null,'பட',(int)$category['Food & groceries'],100,true);
    expense_rule_check(ExpenseCategoryRules::suggest('பட செலவு')['rule_id']===$tamil,'Tamil independent word matches');
    expense_rule_check(ExpenseCategoryRules::suggest('படி செலவு')===null,'Tamil combining vowel sign is not a word boundary');
    $joined=ExpenseCategoryRules::save(null,'ශ්‍රී',(int)$category['Food & groceries'],100,true);
    expense_rule_check(ExpenseCategoryRules::suggest('ශ්‍රී වෙළඳසැල')['rule_id']===$joined,'Legitimate Sinhala ZWJ spelling remains supported');
    $fare=ExpenseCategoryRules::save(null,'fare',(int)$category['Transport'],100,true);
    foreach (["fare\u{200d}shop", "fare\u{200b}shop", 'fare_code', 'fare2', "\u{0301}fare"] as $part) expense_rule_check(ExpenseCategoryRules::suggest($part)===null,'Unicode marks, formatting, connectors and numbers remain inside words');
    expense_rule_check(ExpenseCategoryRules::suggest('fare, paid')['rule_id']===$fare,'Ordinary punctuation separates words');
    $spaced=ExpenseCategoryRules::save(null,"Tea\u{00a0}\t time",(int)$category['Food & groceries'],100,true);
    expense_rule_check(ExpenseCategoryRules::suggest("TEA\u{2003}TIME")['rule_id']===$spaced,'Unicode whitespace normalizes consistently');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'tea time',(int)$category['Transport'],99,false),'Paused duplicate with normalized whitespace rejected');
    $greek=ExpenseCategoryRules::save(null,'ΟΣ',(int)$category['Transport'],100,true);
    Db::run('UPDATE expense_category_rules SET keyword_fold=? WHERE id=?',['ος',$greek]);
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'οσ',(int)$category['Transport'],1,true),'Legacy lowercase Greek final-sigma duplicate rejected');
    ExpenseCategoryRules::save(null,'ſhop',(int)$category['Transport'],100,true);
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save(null,'SHOP',(int)$category['Transport'],1,true),'Unicode long-s duplicate rejected consistently with caseless matching');
    $literal=ExpenseCategoryRules::save(null,'a+b [x]',(int)$category['Transport'],100,true);
    expense_rule_check(ExpenseCategoryRules::suggest('paid a+b [x] today')['rule_id']===$literal&&ExpenseCategoryRules::suggest('paid aaab x today')===null,'Regex syntax in a keyword is matched literally');
    $sql=ExpenseCategoryRules::save(null,"' OR 1=1 --",(int)$category['Transport'],100,true);
    expense_rule_check(ExpenseCategoryRules::suggest("paid ' OR 1=1 -- today")['rule_id']===$sql,'SQL punctuation is safely stored and matched literally');
    $long=ExpenseCategoryRules::save(null,str_repeat('z',80),(int)$category['Transport'],999,true);
    expense_rule_check(ExpenseCategoryRules::suggest(str_repeat('z',80))['rule_id']===$long,'80-character keyword and priority 999 allowed');
    expense_rule_check(ExpenseCategoryRules::suggest(str_repeat('q',5000))===null,'5000-character description allowed');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::suggest(str_repeat('q',5001)),'Oversized description rejected');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::suggest(str_repeat(' ',5001)),'Raw description length bounded before whitespace normalization');
    $other=Db::run('INSERT INTO expense_category_rules(profile_id,keyword,keyword_fold,category_id,priority,enabled) VALUES (?,?,?,?,0,1)',['other','private merchant','private merchant',$category['Transport']]);
    expense_rule_check(ExpenseCategoryRules::suggest('private merchant')===null,'Another logical profile cannot affect suggestions');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::save($other,'stolen rule',(int)$category['Transport'],1,true),'Cannot edit another logical profile rule');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::remove($other),'Cannot remove another logical profile rule');
    $temporaryCategory=Db::run("INSERT INTO expense_categories(name) VALUES ('QA category')");
    $protected=ExpenseCategoryRules::save(null,'protected category',$temporaryCategory,100,true);
    try { Db::run('DELETE FROM expense_categories WHERE id=?',[$temporaryCategory]); throw new RuntimeException('Referenced category was deleted'); }
    catch(PDOException $e) { expense_rule_check(str_contains($e->getMessage(),'FOREIGN KEY'),'Referenced category deletion rejected by foreign key'); }
    expense_rule_check(ExpenseCategoryRules::rule($protected)['category_id']===$temporaryCategory,'Failed category deletion leaves rule valid');
    ExpenseCategoryRules::remove($protected); Db::run('DELETE FROM expense_categories WHERE id=?',[$temporaryCategory]);
    expense_rule_check(ExpenseCategoryRules::suggest('protected category')===null,'Category removable after its rule is deliberately removed');
    expense_rule_check(expense_rule_finances()===$finances,'Rules, rejected writes, deletions and every preview leave all financial tables unchanged');
    $persistent=ExpenseCategoryRules::all(); Db::close(); Db::init($file);
    expense_rule_check(ExpenseCategoryRules::all()===$persistent&&ExpenseCategoryRules::suggest('கட செலவு')===null,'Rules retain exact ordered state across database restart');
    expense_rule_check(ExpenseCategoryRules::suggest('කඩ ගෙවීම')['rule_id']===$sinhala&&ExpenseCategoryRules::suggest('பட செலவு')['rule_id']===$tamil,'Unicode matching persists after restart');
    expense_rule_check(Db::one('PRAGMA integrity_check')['integrity_check']==='ok'&&Db::all('PRAGMA foreign_key_check')===[],'Expense rule database and references remain valid');

    expense_rule_check(Db::all('SELECT * FROM expenses')===[],'Suggestions never create an expense');
    $account=(int)Db::one("SELECT id FROM accounts WHERE name='Needs'")['id'];
    $before=(float)Db::one('SELECT balance FROM accounts WHERE id=?',[$account])['balance'];
    SalaryService::addExpense('2026-11-02',(int)$category['Food & groceries'],$account,'15.25','Bus fare snack');
    expense_rule_check((float)Db::one('SELECT balance FROM accounts WHERE id=?',[$account])['balance']===$before-15.25,'Only explicit expense save debits the account');
    ExpenseCategoryRules::remove($transport);
    expense_rule_check(count(ExpenseCategoryRules::all())===count($persistent)-1 && count(Db::all('SELECT * FROM expenses'))===1,'Deleting a rule preserves posted expenses');
    expense_rule_rejects(fn()=>ExpenseCategoryRules::remove($transport),'Removed rule cannot be deleted twice');
    Db::close();
    echo "$checks expense category rule checks passed.\n";
} finally {
    Db::close();
    foreach ([$file,$file.'-wal',$file.'-shm'] as $path) if (is_file($path)) unlink($path);
}

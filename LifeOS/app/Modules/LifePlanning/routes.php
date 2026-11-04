<?php
declare(strict_types=1);
return function (Router $r) {
    $r->get('/life/legacy',fn()=>redirect('/life'));
    foreach (['/life/plan'=>'plans','/life/plan/item'=>'items','/life/seettu'=>'seettu','/life/seettu/payment'=>'seettu_payments','/life/utility'=>'utilities','/life/utility/reading'=>'readings','/life/vehicle'=>'vehicles','/life/vehicle/service'=>'services','/life/metal'=>'metals','/life/metal/price'=>'prices','/life/deposit'=>'deposits','/life/rate'=>'rates','/life/event'=>'events','/life/event/contribution'=>'contributions','/life/health'=>'health','/life/reminder'=>'reminders','/life/meal'=>'meals','/life/grocery'=>'groceries','/life/wishlist'=>'wishlist','/life/contact'=>'contacts','/life/followup'=>'followups','/life/debt'=>'debts'] as $path=>$kind) {
        $r->post($path,function()use($kind){$input=$_POST;if($kind==='items'&&($input['kind']??'')==='checklist')$input['kind']='task';LifePlanningService::save($kind,$input);return redirect('/life?page='.$kind,'Saved.');});
    }
    $r->post('/life/plan/item/{id}/done',function($p){Db::run('UPDATE life_festival_items SET done=1 WHERE id=?',[record_id($p['id'])]);return redirect('/life#calendar','Checklist item completed.');});
    $r->post('/life/grocery/{id}/bought',function($p){Db::run('UPDATE life_groceries SET bought=1 WHERE id=?',[record_id($p['id'])]);return redirect('/life#health','Grocery item marked bought.');});
    $r->post('/life/wishlist/{id}/status',function($p){LifePlanningService::save('wishlist',['status'=>text_input('status',20)],record_id($p['id']));return redirect('/life?page=wishlist','Wishlist status saved.');});
    $r->post('/life/reminder/{id}/done',function($p){LifePlanningService::complete('reminders',record_id($p['id']),text_input('expected',10));return redirect('/life?page=reminders','Reminder completed.');});
    $r->post('/life/followup/{id}/done',function($p){LifePlanningService::complete('followups',record_id($p['id']),text_input('expected',10));return redirect('/life?page=followups','Follow-up completed.');});
    $r->get('/life',function(){$kind=$_GET['page']??'plans';if(!is_string($kind))throw new InvalidArgumentException(t('Choose a life planning page.'));LifePlanningService::definition($kind);return View::render(__DIR__.'/views/workspace.php',['title'=>t('Life & Sri Lanka'),'kind'=>$kind,'rows'=>LifePlanningService::rows($kind),'editing'=>isset($_GET['edit'])?LifePlanningService::get($kind,record_id($_GET['edit'])):null]);});
    $r->post('/life/manage/{kind}/save',function($p){LifePlanningService::save($p['kind'],$_POST,optional_id($_POST['id']??''));return redirect('/life?page='.urlencode($p['kind']),'Saved.');});
    $r->post('/life/manage/{kind}/{id}/archive',function($p){LifePlanningService::archive($p['kind'],record_id($p['id']));return redirect('/life?page='.urlencode($p['kind']),'Archived.');});
    $r->post('/life/manage/{kind}/{id}/expense',function($p){LifePlanningService::recordExpense($p['kind'],record_id($p['id']),record_id($_POST['account_id']??''),record_id($_POST['category_id']??''),valid_date(text_input('date')));return redirect('/life?page='.urlencode($p['kind']),'One linked Money expense recorded.');});
    $r->post('/life/manage/{kind}/{id}/calendar',function($p){LifePlanningService::calendar($p['kind'],record_id($p['id']));return redirect('/life?page='.urlencode($p['kind']),'Calendar appointment linked.');});
    $r->post('/life/ingredients/{id}/grocery',function($p){LifePlanningService::groceryFromIngredient(record_id($p['id']));return redirect('/life?page=groceries','Ingredient added to groceries once.');});
    $r->post('/life/followups/{id}/task',function($p){LifePlanningService::followupTask(record_id($p['id']));return redirect('/life?page=followups','One task linked for this follow-up occurrence.');});
};

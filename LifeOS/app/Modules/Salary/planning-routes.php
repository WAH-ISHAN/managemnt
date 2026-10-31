<?php
return function (Router $r) {
    $r->get('/salary/plan',function () {
        $month=$_GET['month'] ?? date('Y-m'); if (!is_string($month)) throw new InvalidArgumentException('Choose a valid month.');
        $month=valid_month($month); $plan=FinancePlanningService::plan($month);
        return View::render(__DIR__.'/views/plan.php',['title'=>'Salary planner','month'=>$month,'plan'=>$plan,'projection'=>$plan ? FinancePlanningService::project($plan) : [],'planMonths'=>Db::all('SELECT month FROM salary_plans ORDER BY month DESC LIMIT 24'),'savingsAccounts'=>Db::all("SELECT * FROM accounts WHERE kind='savings' ORDER BY name"),'allocationAccounts'=>Db::all('SELECT * FROM accounts ORDER BY name'),'allocationAssets'=>class_exists('InvestmentService') ? Db::all("SELECT * FROM assets WHERE status='active' ORDER BY name") : [],'allocationSavingsAccounts'=>Db::all("SELECT * FROM accounts WHERE kind='savings' ORDER BY name")]);
    });
    $r->post('/salary/plan/preview',function () {
        try { $math=FinancePlanningService::calculate($_POST); $problem=''; }
        catch (InvalidArgumentException $e) { $math=null; $problem=$e->getMessage(); }
        return View::render(__DIR__.'/views/plan-preview.php',compact('math','problem'),'');
    });
    $r->post('/salary/plan',function () { FinancePlanningService::savePlan($_POST); return redirect('/salary/plan?month='.rawurlencode((string)$_POST['month']),'Monthly salary plan saved. No income or expenses were recorded by saving this plan.'); });
    $r->post('/salary/plan/allocations',function () {
        $month=valid_month(text_input('month'));
        FinancePlanningService::applyPlanAllocations($month,record_id($_POST['spending_account_id'] ?? ''),optional_id($_POST['savings_account_id'] ?? ''),optional_id($_POST['investment_asset_id'] ?? ''));
        return redirect('/salary/plan?month='.rawurlencode($month),'Allocation rules now use this plan\'s base rates. They apply to new income; the investment share goes directly to your chosen asset.');
    });
    $r->get('/salary/calendar',function () {
        return View::render(__DIR__.'/views/calendar.php',['title'=>'Financial calendar','due'=>FinancePlanningService::dueItems(365),'obligations'=>Db::all('SELECT o.*,a.name account,c.name category FROM financial_obligations o JOIN accounts a ON a.id=o.account_id JOIN expense_categories c ON c.id=o.category_id ORDER BY o.active DESC,o.due_date,o.id'),'payments'=>Db::all('SELECT p.*,o.title,o.kind FROM financial_payments p JOIN financial_obligations o ON o.id=p.obligation_id ORDER BY p.paid_date DESC,p.id DESC LIMIT 50'),'accounts'=>Db::all('SELECT * FROM accounts ORDER BY id'),'categories'=>Db::all('SELECT * FROM expense_categories ORDER BY name'),'totals'=>FinancePlanningService::dailyWeeklyTotals()]);
    });
    $r->post('/salary/obligations',function () { FinancePlanningService::addObligation($_POST); return redirect('/salary/calendar','Financial reminder saved. An expense is recorded when you mark the payment paid.'); });
    $r->post('/salary/obligations/{id}/pay',function ($p) { FinancePlanningService::payObligation(record_id($p['id']),text_input('due_date'),text_input('paid_date')); return redirect('/salary/calendar','Payment recorded and its account balance updated.'); });
    $r->post('/salary/obligations/{id}/status',function ($p) {
        $id=record_id($p['id']); $active=int_range($_POST['active'] ?? '',0,1);
        $row=Db::one('SELECT * FROM financial_obligations WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Financial reminder not found.');
        if ($active && $row['kind']==='loan' && $row['outstanding']<=0) throw new InvalidArgumentException('This loan is already repaid.');
        if ($active && $row['interval']==='none' && Db::one("SELECT id FROM financial_payments WHERE obligation_id=? AND due_date=? AND status='paid'",[$id,$row['due_date']])) throw new InvalidArgumentException('This one-off commitment is paid. Reverse its matching expense first if needed.');
        Db::run('UPDATE financial_obligations SET active=? WHERE id=?',[$active,$id]); return redirect('/salary/calendar',$active ? 'Reminder activated.' : 'Reminder paused.');
    });
    $r->get('/salary/recurring',function () {
        $run=FinancePlanningService::processDue();
        return View::render(__DIR__.'/views/recurring.php',['title'=>'Recurring transactions','run'=>$run,'rows'=>Db::all('SELECT r.*,a.name account,c.name category FROM financial_recurrences r LEFT JOIN accounts a ON a.id=r.account_id LEFT JOIN expense_categories c ON c.id=r.category_id ORDER BY r.active DESC,r.next_date,r.id'),'history'=>Db::all('SELECT f.*,r.title,r.kind FROM financial_occurrences f JOIN financial_recurrences r ON r.id=f.recurrence_id ORDER BY f.due_date DESC,f.id DESC LIMIT 50'),'accounts'=>Db::all('SELECT * FROM accounts ORDER BY id'),'categories'=>Db::all('SELECT * FROM expense_categories ORDER BY name')]);
    });
    $r->post('/salary/recurring',function () { FinancePlanningService::addRecurrence($_POST); return redirect('/salary/recurring','Recurring transaction saved. Enabled transactions are recorded when due while the application is open.'); });
    $r->post('/salary/recurring/{id}/status',function ($p) { FinancePlanningService::setRecurrence(record_id($p['id']),int_range($_POST['active'] ?? '',0,1)===1); return redirect('/salary/recurring','Recurring transaction updated.'); });
    $r->post('/salary/recurring/run',function () { $run=FinancePlanningService::processDue(); return redirect('/salary/recurring',$run['processed'].' due transactions recorded.'.($run['remaining'] ? ' More are due; run again to continue safely.' : '')); });
};

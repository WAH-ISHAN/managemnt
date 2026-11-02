<?php
return function (Router $r) {
    $r->get('/salary', function () {
        $month = $_GET['month'] ?? date('Y-m');
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month) || (int)substr($month,0,4)<1900) throw new InvalidArgumentException('Choose a valid month.');
        $start = $month.'-01'; $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        return View::render(__DIR__.'/views/index.php',array_merge(SalaryService::overview($month),[
            'title'=>'Money','month'=>$month,'anyFinancialData'=>(int)Db::one('SELECT (SELECT COUNT(*) FROM income)+(SELECT COUNT(*) FROM expenses) n')['n']>0,'periodHasData'=>(int)Db::one('SELECT (SELECT COUNT(*) FROM income WHERE date>=? AND date<?)+(SELECT COUNT(*) FROM expenses WHERE date>=? AND date<?) n',[$start,$end,$start,$end])['n']>0,'dailyTotals'=>FinancePlanningService::dailyWeeklyTotals(),'series'=>SalaryService::monthlySeries(),'accounts'=>Db::all('SELECT * FROM accounts ORDER BY id'),
            'categories'=>Db::all('SELECT * FROM expense_categories ORDER BY name'),
            'rows'=>Db::all('SELECT * FROM income WHERE date>=? AND date<? ORDER BY date DESC,id DESC',[$start,$end]),
            'expenseRows'=>Db::all('SELECT e.*,c.name category,a.name account FROM expenses e JOIN expense_categories c ON c.id=e.category_id JOIN accounts a ON a.id=e.account_id WHERE e.date>=? AND e.date<? ORDER BY e.date DESC,e.id DESC',[$start,$end]),
            'parts'=>Db::all('SELECT a.* FROM allocations a JOIN income i ON i.id=a.income_id WHERE i.date>=? AND i.date<? ORDER BY a.id',[$start,$end])
        ]));
    });
    $r->post('/salary',function () { SalaryService::addIncome(text_input('date'),text_input('source'),$_POST['amount'] ?? '',text_input('type')); return redirect('/salary','Income added and allocated.'); });
    $r->post('/salary/{id}/delete',function ($p) { SalaryService::deleteIncome(record_id($p['id'])); return redirect('/salary','Income removed and its allocations reversed.'); });
    $r->post('/expenses',function () { SalaryService::addExpense(text_input('date'),record_id($_POST['category'] ?? ''),record_id($_POST['account'] ?? ''),$_POST['amount'] ?? '',text_input('note',1000,false)); return redirect('/salary','Expense recorded.'); });
    $r->post('/expenses/{id}/delete',function ($p) { SalaryService::deleteExpense(record_id($p['id'])); return redirect('/salary','Expense removed and account balance restored.'); });
    $r->get('/salary/expense-rules',function () {
        return View::render(__DIR__.'/views/expense-rules.php',[
            'title'=>'Expense category rules','rules'=>ExpenseCategoryRules::all(),
            'editing'=>isset($_GET['edit']) ? ExpenseCategoryRules::rule(record_id($_GET['edit'])) : null,
            'categories'=>Db::all('SELECT id,name FROM expense_categories ORDER BY name'),
            'accounts'=>Db::all('SELECT id,name FROM accounts ORDER BY id'),
            'preview'=>null,
        ]);
    });
    $r->post('/salary/expense-rules',function () {
        ExpenseCategoryRules::save(optional_id($_POST['id'] ?? ''),text_input('keyword',80),record_id($_POST['category'] ?? ''),int_range($_POST['priority'] ?? '',0,999),isset($_POST['enabled']));
        return redirect('/salary/expense-rules','Category rule saved. Suggestions apply to future previews; existing expenses are unchanged.');
    });
    $r->post('/salary/expense-rules/{id}/delete',function ($p) {
        ExpenseCategoryRules::remove(record_id($p['id']));
        return redirect('/salary/expense-rules','Category rule removed. Existing expenses are unchanged.');
    });
    $r->post('/salary/expense-rules/preview',function () {
        $description=text_input('description',1000);
        return View::render(__DIR__.'/views/expense-rules.php',[
            'title'=>'Expense category rules','rules'=>ExpenseCategoryRules::all(),'editing'=>null,
            'categories'=>Db::all('SELECT id,name FROM expense_categories ORDER BY name'),
            'accounts'=>Db::all('SELECT id,name FROM accounts ORDER BY id'),
            'preview'=>['description'=>$description,'suggestion'=>ExpenseCategoryRules::suggest($description)],
        ]);
    });
    $alertPage=function (string $alert='',?array $preview=null,string $error=''): string {
        return View::render(__DIR__.'/views/alert-import.php',[
            'title'=>'Review a bank alert','alert'=>$alert,'preview'=>$preview,'error'=>$error,
            'categories'=>Db::all('SELECT id,name FROM expense_categories ORDER BY name'),
            'accounts'=>Db::all('SELECT id,name FROM accounts ORDER BY id'),
        ]);
    };
    $r->get('/salary/alert-import',fn()=> $alertPage());
    $r->post('/salary/alert-import/preview',function () use ($alertPage): string {
        $alert=text_input('alert',3000);
        try { return $alertPage($alert,ExpenseAlertImport::preview($alert)); }
        catch (InvalidArgumentException $error) { http_response_code(422); return $alertPage($alert,null,$error->getMessage()); }
    });
    $r->post('/salary/alert-import/post',function () use ($alertPage): string {
        $alert=text_input('alert',3000);
        try {
            ExpenseAlertImport::post($alert,text_input('date'),$_POST['amount'] ?? '',record_id($_POST['category'] ?? ''),record_id($_POST['account'] ?? ''),text_input('note',1000,false));
            return redirect('/salary','Reviewed bank alert recorded as one expense.');
        } catch (InvalidArgumentException $error) {
            http_response_code(422);
            try { $preview=ExpenseAlertImport::preview($alert); }
            catch (InvalidArgumentException) { $preview=null; }
            return $alertPage($alert,$preview,$error->getMessage());
        }
    });
    (require __DIR__.'/planning-routes.php')($r);
};

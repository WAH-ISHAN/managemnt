<?php
return function (Router $r) {
    $r->get('/why/net-worth',function () {
        $accounts=Db::all('SELECT name,kind,balance FROM accounts ORDER BY id');
        $assets=(module_enabled('Investments') && class_exists('InvestmentService')) ? InvestmentService::allWithSummary() : [];
        $debt=(module_enabled('Salary') && class_exists('FinancePlanningService')) ? FinancePlanningService::outstandingDebt() : 0;
        $debts=(module_enabled('Salary') && class_exists('FinancePlanningService')) ? Db::all("SELECT id,title,outstanding,due_date FROM financial_obligations WHERE kind='loan' ORDER BY due_date,id") : [];
        return View::render(__DIR__.'/views/net-worth.php',['title'=>t('Why this net worth?'),'accounts'=>$accounts,'assets'=>$assets,'debt'=>$debt,'debts'=>$debts]);
    });
    $r->get('/',function () {
        if (class_exists('OnboardingService') && OnboardingService::needed()) return redirect('/welcome');
        $day=DailyProgressService::date($_GET['date'] ?? date('Y-m-d'));
        $salary=(module_enabled('Salary') && class_exists('SalaryService')); $invest=(module_enabled('Investments') && class_exists('InvestmentService')); $uni=(module_enabled('Uni') && class_exists('UniService')); $lessons=(module_enabled('Lessons') && class_exists('LessonService'));
        $summary=$salary ? SalaryService::overview(date('Y-m')) : ['income'=>0,'expenses'=>0,'saved'=>0,'budgets'=>[]];
        $accounts=$salary ? Db::all('SELECT * FROM accounts ORDER BY id') : [];
        $balance=array_sum(array_column($accounts,'balance')); $assets=$invest ? InvestmentService::allWithSummary() : [];
        usort($assets,fn($a,$b)=>$b['current']<=>$a['current']);
        return View::render(__DIR__.'/views/index.php',array_merge($summary,[
            'hasFocus'=>module_enabled('Focus'),'hasGrowth'=>module_enabled('Growth'),'hasFreedom'=>module_enabled('Freedom'),'title'=>t('Overview'),'hasSalary'=>$salary,'hasInvestments'=>$invest,'hasUni'=>$uni,'hasLessons'=>$lessons,
            'selectedDay'=>$day,'dailyProgress'=>DailyProgressService::day($day),'dailyHistory'=>DailyProgressService::week($day),'suggestions'=>DailyProgressService::suggestions(),
            'name'=>Db::setting('name'),'accounts'=>$accounts,'balance'=>$balance,'netWorth'=>$balance+($invest ? InvestmentService::netWorth() : 0)-((module_enabled('Salary') && class_exists('FinancePlanningService')) ? FinancePlanningService::outstandingDebt() : 0),
            'series'=>$salary ? SalaryService::monthlySeries() : [],'assets'=>$assets,'goals'=>$invest ? InvestmentService::goals() : [],
            'rules'=>$salary ? Db::all('SELECT * FROM allocation_rules WHERE active=1 ORDER BY sort,id') : [],
            'due'=>$uni ? UniService::upcoming(7) : [],'exams'=>$uni ? UniService::exams(30) : [],'gpa'=>$uni ? UniService::gpa() : null,
            'tasks'=>Db::all("SELECT * FROM tasks WHERE status='open' ORDER BY due IS NULL,due,id LIMIT 8"),
            'repeated'=>$lessons ? LessonService::repeated() : [],'review'=>$lessons ? Db::one('SELECT * FROM weekly_reviews ORDER BY week DESC LIMIT 1') : null,
            'hasMoneyRecords'=>$salary && (Db::one('SELECT 1 FROM income LIMIT 1') || Db::one('SELECT 1 FROM expenses LIMIT 1')),
            'hasAssetRecords'=>$invest && (bool)Db::one('SELECT 1 FROM asset_transactions LIMIT 1'),
            'personalGoals'=>(module_enabled('Growth') && class_exists('GrowthService')) ? GrowthService::goals() : [],
            'appointments'=>(module_enabled('Planner') && class_exists('PlannerService')) ? PlannerService::upcoming(2) : [],
            'focus'=>(module_enabled('Focus') && class_exists('FocusService')) ? FocusService::active() : null,
            'lifeSummary'=>(module_enabled('Insights') && class_exists('InsightsService')) ? InsightsService::monthly(date('Y-m')) : null
        ]));
    });
};

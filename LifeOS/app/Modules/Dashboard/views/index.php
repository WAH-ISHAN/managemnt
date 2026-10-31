<div class="dashboard-home">
<header class="page-header dashboard-header">
    <div>
        <p class="dashboard-date"><?= e(date('l, j F Y')) ?><?= $name ? ' | '.e($name) : '' ?></p>
        <h1>Your day, in perspective.</h1>
        <p class="muted">Your tasks, study and money in one place.</p>
    </div>
    <a class="btn" href="/planner?view=tasks#new-task">+ Add a task</a>
</header>
<?php require ROOT.'/app/Core/views/notices.php'; ?>

<nav class="dashboard-actions" aria-label="Quick actions">
    <?php if ($hasSalary): ?>
        <a href="/salary#add-expense"><?= icon('wallet') ?><span>Add expense</span></a>
        <a href="/salary#add-income"><?= icon('wallet') ?><span>Add income</span></a>
        <a href="/salary/plan"><?= icon('calendar') ?><span>Plan your salary</span></a>
    <?php endif; ?>
    <a href="/focus"><?= icon('clock') ?><span><?= $focus ? 'Return to focus' : 'Start study focus' ?></span></a>
</nav>

<?php require __DIR__.'/daily-progress.php'; ?>
<div class="two-col dashboard-primary">
    <section class="card">
        <div class="section-heading"><h2>Today</h2><a href="/planner">Open planner &rarr;</a></div>
        <?php if ($focus): ?>
            <div class="dashboard-focus"><div><strong><?= e($focus['subject']) ?></strong><small><?= e(ucfirst($focus['kind'])) ?> session | <?= e($focus['status']) ?></small></div><a class="btn secondary" href="/focus">Continue</a></div>
        <?php endif; ?>
        <h3>Your next actions</h3>
        <?php if ($hasLessons): $returnTo='/'; require ROOT.'/app/Modules/Lessons/views/tasks.php'; elseif (!$tasks): ?>
            <p class="muted dashboard-empty">No unfinished tasks. <a href="/planner?view=tasks#new-task">Add your next task.</a></p>
        <?php else: foreach ($tasks as $task): ?>
            <div class="task-row"><div><strong><?= e($task['title']) ?></strong><small><?= e($task['due'] ?? 'No due date') ?></small></div><a href="/planner?view=tasks">Open</a></div>
        <?php endforeach; endif; ?>
        <div class="section-heading"><h3>Your schedule</h3><a href="/planner?view=appointments">Manage &rarr;</a></div>
        <?php foreach (array_slice($appointments,0,3) as $appointment): ?>
            <div class="deadline"><strong><?= e($appointment['title']) ?></strong><small><?= e($appointment['starts_at']) ?></small></div>
        <?php endforeach; ?>
        <?php if (!$appointments): ?><p class="muted dashboard-empty">No upcoming appointments recorded.</p><?php endif; ?>
    </section>

    <section class="card dashboard-money">
        <div class="section-heading"><h2>Money <span class="muted">&middot; <?= e(date('F')) ?></span></h2><a href="/salary">Open Money &rarr;</a></div>
        <?php if ($hasMoneyRecords || $hasAssetRecords): ?>
            <dl class="dashboard-money-metrics">
                <div><dt><a href="/why/net-worth">Recorded net worth · Why?</a></dt><dd><?= money($netWorth) ?></dd></div>
                <div><dt>Income this month</dt><dd><?= $hasMoneyRecords ? money($income) : 'No data yet' ?></dd></div>
                <div><dt>Expenses this month</dt><dd><?= $hasMoneyRecords ? money($expenses) : 'No data yet' ?></dd></div>
                <div><dt>Savings rate</dt><dd><?= $income>0 ? e(number_format(($income-$expenses)/$income*100,1)).'%' : 'No data yet' ?></dd></div>
            </dl>
            <?php if ($hasMoneyRecords): ?><?= chart('bar',array_column($series,'month'),[['label'=>'Income','data'=>array_column($series,'income'),'backgroundColor'=>'#0071e3'],['label'=>'Expenses','data'=>array_column($series,'expenses'),'backgroundColor'=>'#9faec3']],['plugins'=>['lifeos-cashflow-depth'=>['enabled'=>true]]]) ?><?php endif; ?>
        <?php else: ?><p class="muted dashboard-empty">No data yet. Start with your salary plan, then record income and daily spending.</p><?php endif; ?>
        <div class="hero-actions"><a class="btn secondary" href="/salary/plan">Plan your salary</a><a href="/salary#add-expense">Add expense</a></div>
    </section>
</div>

<details class="dashboard-details"><summary>Study, goals & reviews <span>Open your longer-term progress</span></summary><div class="two-col dashboard-secondary">
    <section class="card">
        <div class="section-heading"><h2>Study</h2><a href="/uni">Open University &rarr;</a></div>
        <dl class="dashboard-summary">
            <div><dt>University GPA</dt><dd><?= $gpa===null ? '<span class="muted">No data yet</span>' : e(number_format($gpa,2)) ?></dd></div>
            <div><dt>Focus this month</dt><dd><?= ($lifeSummary['focus_minutes'] ?? 0)>0 ? e($lifeSummary['focus_minutes']).' min' : '<span class="muted">No sessions yet</span>' ?></dd></div>
        </dl>
        <h3>Upcoming deadlines</h3>
        <?php foreach ($due as $assignment): ?>
            <div class="deadline"><strong><?= e($assignment['title']) ?></strong><small class="<?= $assignment['due_date']<date('Y-m-d') ? 'error-text' : '' ?>"><?= e($assignment['due_date']) ?><?= $assignment['due_date']<date('Y-m-d') ? ' | Overdue' : '' ?> | <?= e($assignment['module']) ?></small></div>
        <?php endforeach; ?>
        <?php foreach ($exams as $exam): ?>
            <div class="deadline"><strong>Exam &middot; <?= e($exam['module']) ?></strong><small><?= e($exam['date']) ?><?= $exam['venue']!=='' ? ' | '.e($exam['venue']) : '' ?></small></div>
        <?php endforeach; ?>
        <?php if (!$due && !$exams): ?><p class="muted dashboard-empty">No upcoming deadlines. <a href="/uni?view=modules">Add your modules and dates.</a></p><?php endif; ?>
    </section>

    <section class="card">
        <div class="section-heading"><h2>Goals</h2><a href="/growth">Open Goals &rarr;</a></div>
        <?php if (!$personalGoals): ?><p class="muted dashboard-empty">Choose one thing you want to work toward. <a href="/growth">Add a goal.</a></p>
        <?php else: foreach (array_slice($personalGoals,0,3) as $goal): ?>
            <div class="budget-row"><div><a href="/growth/<?= e($goal['id']) ?>"><strong><?= e($goal['title']) ?></strong></a></div><?= progress_donut((float)GrowthService::progress($goal),'Goal progress','',true) ?><small><?= e($goal['done_count']) ?> of <?= e($goal['step_count']) ?> steps | <?= e($goal['status']) ?></small></div>
        <?php endforeach; endif; ?>
        <h3>Weekly review</h3>
        <?php if ($review): ?>
            <p><strong><?= e($review['score']) ?>/10</strong> <span class="muted">| <?= e($review['week']) ?></span></p>
            <?php if ($review['focus']!==''): ?><p><?= e($review['focus']) ?></p><?php endif; ?>
        <?php else: ?><p class="muted dashboard-empty">Record what went well and what to change next week.</p><?php endif; ?>
        <div class="hero-actions"><a href="/review">Weekly review &rarr;</a><a href="/insights">Monthly review</a></div>
    </section>
</div>

</details>
<?php foreach ($repeated as $pattern): ?><div class="notice warning"><strong>Recurring pattern &middot; <?= e($pattern['area']) ?></strong> &mdash; <?= e($pattern['n']) ?> failures logged in 90 days. <a href="/lessons">Review the lessons &rarr;</a></div><?php endforeach; ?>
<?php foreach ($budgets as $budget) if ($budget['monthly_budget']>0 && $budget['spent']>$budget['monthly_budget']): ?><div class="notice warning"><strong>Over budget &middot; <?= e($budget['name']) ?></strong> &mdash; <?= money($budget['spent']) ?> spent against <?= money($budget['monthly_budget']) ?>. <a href="/salary">Review spending &rarr;</a></div><?php endif; ?>
</div>

<div class="dashboard-home">
<?php $firstName=trim(explode(' ',trim((string)$name))[0] ?? ''); ?>
<div class="dashboard-hero"><div class="dashboard-hero-copy">
    <p class="dashboard-date"><?= e(ui_date('l, j F Y')) ?></p>
    <h1><?= e(t('Good')) ?> <?= e((int)ui_date('G')<12 ? t('morning') : ((int)ui_date('G')<17 ? t('afternoon') : t('evening'))) ?><?= $firstName!=='' ? ', '.e($firstName) : '' ?>.</h1>
    <p><?= e(PersonaService::description()) ?></p>
</div><span class="dashboard-hero-orb" aria-hidden="true"></span></div>
<nav class="tabs" aria-label="<?= e(t('Your workspace tools')) ?>"><?php foreach(['Employee'=>['/employee','Employee workspace'],'StudyTools'=>['/study','University study tools']]as $module=>[$route,$label]):if(!module_enabled($module))continue; ?><a href="<?= e($route) ?>"><?= e(t($label)) ?></a><?php endforeach; ?></nav>
<header class="page-header dashboard-header sr-only">
    <div>
        <p class="dashboard-date"><?= e(ui_date('l, j F Y')) ?><?= $name ? ' | '.e($name) : '' ?></p>
        <h1><?= e(t('Today')) ?><?php if (!PersonaService::profile()): ?><?= e(t(': Your day, in perspective.')) ?><?php endif; ?></h1>
        <p class="muted"><?= e(PersonaService::description()) ?></p>
    </div>
    <a class="btn" href="/planner?view=tasks#new-task"><?= e(t('+ Add a task')) ?></a>
</header>
<?php require ROOT.'/app/Core/views/notices.php'; ?>

<nav class="dashboard-actions" aria-label="<?= e(t('Quick actions')) ?>">
    <a class="dashboard-action-primary" href="/planner?view=tasks#new-task"><?= icon('calendar') ?><span><?= e(t('Add a task')) ?></span></a>
    <?php if ($hasSalary): ?>
        <a href="/salary#add-expense"><?= icon('wallet') ?><span><?= e(t('Add expense')) ?></span></a>
        <a href="/salary#add-income"><?= icon('wallet') ?><span><?= e(t('Add income')) ?></span></a>
        <a href="/salary/plan"><?= icon('calendar') ?><span><?= e(t('Plan your salary')) ?></span></a>
    <?php endif; ?>
    <?php if ($hasFocus): ?><a href="/focus"><?= icon('clock') ?><span><?= $focus ? t('Return to focus') : t('Start study focus') ?></span></a><?php endif; ?>
    <a href="/knowledge/daily"><?= icon('book') ?><span><?= e(t('Today’s note')) ?></span></a>
</nav>

<div class="dashboard-bento"><div class="dashboard-main-column">
<?php require __DIR__.'/daily-progress.php'; ?>
<?php if ($pinnedNotes): ?><section class="card dashboard-pinned-notes" aria-labelledby="pinned-notes-title"><div class="section-heading"><h2 id="pinned-notes-title"><?= e(t('Pinned notes')) ?></h2><a href="/knowledge"><?= e(t('Knowledge Hub →')) ?></a></div><div class="knowledge-actions"><?php foreach ($pinnedNotes as $note): ?><a href="/knowledge/<?= e($note['id']) ?>"><?= e($note['title']) ?> <small>· <?= e($note['notebook']) ?></small></a><?php endforeach; ?></div></section><?php endif; ?>
<div class="two-col dashboard-primary">
    <section class="card">
        <div class="section-heading"><h2><?= e(t('Today')) ?></h2><a href="/planner"><?= e(t('Open planner →')) ?></a></div>
        <?php if ($focus): ?>
            <div class="dashboard-focus"><div><strong><?= e($focus['subject']) ?></strong><small><?= e(ucfirst($focus['kind'])) ?> <?= e(t('session |')) ?> <?= e($focus['status']) ?></small></div><a class="btn secondary" href="/focus"><?= e(t('Continue')) ?></a></div>
        <?php endif; ?>
        <h3><?= e(t('Your next actions')) ?></h3>
        <?php require __DIR__.'/today-categories.php'; ?>
        <div class="section-heading"><h3><?= e(t('Your schedule')) ?></h3><a href="/planner?view=appointments"><?= e(t('Manage →')) ?></a></div>
        <?php foreach (array_slice($appointments,0,3) as $appointment): ?>
            <div class="deadline"><strong><?= e($appointment['title']) ?></strong><small><?= e(!empty($appointment['all_day']) ? PlannerService::appointmentWhen($appointment) : $appointment['starts_at']) ?></small></div>
        <?php endforeach; ?>
        <?php if (!$appointments): ?><p class="muted dashboard-empty"><?= e(t('No upcoming appointments recorded.')) ?></p><?php endif; ?>
    </section>

    <?php if ($hasSalary): ?><section class="card dashboard-money">
        <div class="section-heading"><h2><?= e(t('Money')) ?> <span class="muted">&middot; <?= e(ui_date('F')) ?></span></h2><a href="/salary"><?= e(t('Open Money →')) ?></a></div>
        <?php if ($hasMoneyRecords || $hasAssetRecords): ?>
            <dl class="dashboard-money-metrics">
                <div><dt><a href="/why/net-worth"><?= e(t('Recorded net worth · Why?')) ?></a></dt><dd><?= money($netWorth) ?></dd></div>
                <div><dt><?= e(t('Income this month')) ?></dt><dd><?= $hasMoneyRecords ? money($income) : t('No data yet') ?></dd></div>
                <div><dt><?= e(t('Expenses this month')) ?></dt><dd><?= $hasMoneyRecords ? money($expenses) : t('No data yet') ?></dd></div>
                <div><dt><?= e(t('Savings rate')) ?></dt><dd><?= $income>0 ? e(number_format(($income-$expenses)/$income*100,1)).'%' : t('No data yet') ?></dd></div>
            </dl>
            <?php if ($hasMoneyRecords): ?><?= chart('bar',array_column($series,'month'),[['label'=>t('Income'),'data'=>array_column($series,'income'),'backgroundColor'=>'#0071e3'],['label'=>t('Expenses'),'data'=>array_column($series,'expenses'),'backgroundColor'=>'#9faec3']],['plugins'=>['lifeos-cashflow-depth'=>['enabled'=>true]]]) ?><?php endif; ?>
        <?php else: ?><p class="muted dashboard-empty"><?= e(t('No data yet. Start with your salary plan, then record income and daily spending.')) ?></p><?php endif; ?>
        <div class="hero-actions"><a class="btn secondary" href="/salary/plan"><?= e(t('Plan your salary')) ?></a><a href="/salary#add-expense"><?= e(t('Add expense')) ?></a></div>
    </section>
<?php endif; ?>
</div>

</div><aside class="dashboard-rail" aria-label="<?= e(t('Your day at a glance')) ?>">
    <?php if (module_enabled('Intelligence')): ?><section class="card dashboard-ai-card" aria-labelledby="dashboard-ai-title"><span class="dashboard-rail-icon" aria-hidden="true"><?= icon('sparkles') ?></span><h2 id="dashboard-ai-title"><?= e(t('LifeOS AI')) ?></h2><p class="muted"><?= e(t('Think through your day with your personal assistant.')) ?></p><button type="button" class="btn secondary" data-assistant-open><?= e(t('Ask LifeOS AI')) ?> <span aria-hidden="true">→</span></button></section><?php endif; ?>
    <?php if ($hasFocus): ?><section class="card dashboard-focus-card" aria-labelledby="dashboard-focus-title"><span class="dashboard-rail-icon" aria-hidden="true"><?= icon('clock') ?></span><h2 id="dashboard-focus-title"><?= e(t('Focus time')) ?></h2><?php if ($focus): ?><p><strong><?= e($focus['subject']) ?></strong><small><?= e(ucfirst($focus['kind'])) ?> · <?= e($focus['status']) ?></small></p><?php else: ?><p class="muted"><?= e(t('Make room for one meaningful session.')) ?></p><?php endif; ?><a class="btn secondary" href="/focus"><?= e(t($focus ? 'Continue focus' : 'Start focus')) ?> <span aria-hidden="true">→</span></a></section><?php endif; ?>
    <section class="card dashboard-upcoming" aria-labelledby="dashboard-upcoming-title"><span class="dashboard-rail-icon" aria-hidden="true"><?= icon('calendar') ?></span><h2 id="dashboard-upcoming-title"><?= e(t('Upcoming')) ?></h2><?php foreach (array_slice($suggestions,0,4) as $item): ?><a class="dashboard-upcoming-item" href="<?= e($item['route']) ?>"><small><?= e($item['when']) ?> · <?= e($item['kind']) ?></small><strong><?= e($item['title']) ?></strong></a><?php endforeach; ?><?php if (!$suggestions): ?><p class="muted"><?= e(t('No upcoming dates recorded.')) ?></p><?php endif; ?><a class="dashboard-upcoming-all" href="/planner"><?= e(t('Open planner')) ?> <span aria-hidden="true">→</span></a></section>
</aside></div>

<details class="dashboard-details"><summary><?= e(t(module_enabled('Uni')?'Study, goals & reviews':'Focus, goals & reviews')) ?> <span><?= e(t('Open your longer-term progress')) ?></span></summary><div class="two-col dashboard-secondary">
    <?php if ($hasUni || $hasFocus): ?><section class="card">
        <div class="section-heading"><h2><?= e(t($hasUni?'Study':'Focus')) ?></h2><?php if ($hasUni): ?><a href="/uni"><?= e(t('Open University →')) ?></a><?php endif; ?></div>
        <dl class="dashboard-summary">
            <?php if ($hasUni): ?><div><dt><?= e(t('University GPA')) ?></dt><dd><?= $gpa===null ? ui_markup('<span class="muted">No data yet</span>') : e(number_format($gpa,2)) ?></dd></div><?php endif; ?>
            <div><dt><?= e(t('Focus this month')) ?></dt><dd><?= ($lifeSummary['focus_minutes'] ?? 0)>0 ? e($lifeSummary['focus_minutes']).' min' : ui_markup('<span class="muted">No sessions yet</span>') ?></dd></div>
        </dl>
        <?php if($hasUni): ?><h3><?= e(t('Upcoming deadlines')) ?></h3><?php endif; ?>
        <?php foreach ($due as $assignment): ?>
            <div class="deadline"><strong><?= e($assignment['title']) ?></strong><small class="<?= $assignment['due_date']<ui_date('Y-m-d') ? 'error-text' : '' ?>"><?= e($assignment['due_date']) ?><?= $assignment['due_date']<ui_date('Y-m-d') ? ' | Overdue' : '' ?> | <?= e($assignment['module']) ?></small></div>
        <?php endforeach; ?>
        <?php foreach ($exams as $exam): ?>
            <div class="deadline"><strong><?= e(t('Exam ·')) ?> <?= e($exam['module']) ?></strong><small><?= e($exam['date']) ?><?= $exam['venue']!=='' ? ' | '.e($exam['venue']) : '' ?></small></div>
        <?php endforeach; ?>
        <?php if ($hasUni && !$due && !$exams): ?><p class="muted dashboard-empty"><?= e(t('No upcoming deadlines.')) ?> <a href="/uni?view=modules"><?= e(t('Add your modules and dates.')) ?></a></p><?php endif; ?>
    </section><?php endif; ?>

    <?php if ($hasGrowth || $hasLessons): ?><section class="card">
        <?php if ($hasGrowth): ?><div class="section-heading"><h2><?= e(t('Goals')) ?></h2><a href="/growth"><?= e(t('Open Goals →')) ?></a></div>
        <?php if (!$personalGoals): ?><p class="muted dashboard-empty"><?= e(t('Choose one thing you want to work toward.')) ?> <a href="/growth"><?= e(t('Add a goal.')) ?></a></p>
        <?php else: foreach (array_slice($personalGoals,0,3) as $goal): ?>
            <div class="budget-row"><div><a href="/growth/<?= e($goal['id']) ?>"><strong><?= e($goal['title']) ?></strong></a></div><?= progress_donut((float)GrowthService::progress($goal),'Goal progress','',true) ?><small><?= e($goal['done_count']) ?> <?= e(t('of')) ?> <?= e($goal['step_count']) ?> <?= e(t('steps |')) ?> <?= e($goal['status']) ?></small></div>
        <?php endforeach; endif; ?>
        <?php endif; ?><?php if ($hasLessons): ?><h3><?= e(t('Weekly review')) ?></h3>
        <?php if ($review): ?>
            <p><strong><?= e($review['score']) ?>/10</strong> <span class="muted">| <?= e($review['week']) ?></span></p>
            <?php if ($review['focus']!==''): ?><p><?= e($review['focus']) ?></p><?php endif; ?>
        <?php else: ?><p class="muted dashboard-empty"><?= e(t('Record what went well and what to change next week.')) ?></p><?php endif; ?>
        <div class="hero-actions"><a href="/review"><?= e(t('Weekly review →')) ?></a><a href="/insights"><?= e(t('Monthly review')) ?></a></div><?php endif; ?>
    </section><?php endif; ?>
</div>

</details>
<?php foreach ($repeated as $pattern): ?><div class="notice warning"><strong><?= e(t('Recurring pattern ·')) ?> <?= e($pattern['area']) ?></strong> &mdash; <?= e($pattern['n']) ?> <?= e(t('failures logged in 90 days.')) ?> <a href="/lessons"><?= e(t('Review the lessons →')) ?></a></div><?php endforeach; ?>
<?php foreach ($budgets as $budget) if ($budget['monthly_budget']>0 && $budget['spent']>$budget['monthly_budget']): ?><div class="notice warning"><strong><?= e(t('Over budget ·')) ?> <?= e($budget['name']) ?></strong> &mdash; <?= money($budget['spent']) ?> <?= e(t('spent against')) ?> <?= money($budget['monthly_budget']) ?>. <a href="/salary"><?= e(t('Review spending →')) ?></a></div><?php endif; ?>
</div>

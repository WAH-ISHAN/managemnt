<?php
$todayGroups=[
    ['key'=>'exams','label'=>t('Exams'),'empty'=>t('No exams yet.')],
    ['key'=>'todos','label'=>t('To-dos'),'empty'=>t('No to-dos yet.')],
    ['key'=>'tasks','label'=>t('Other tasks'),'empty'=>t('No other tasks yet.')],
];
?>
<div class="today-categories">
<?php foreach ($todayGroups as $group): $rows=$taskCategories[$group['key']]; ?>
    <section class="today-category" data-today-category="<?= e($group['key']) ?>" aria-labelledby="today-<?= e($group['key']) ?>-title">
        <h4 id="today-<?= e($group['key']) ?>-title"><?= e($group['label']) ?></h4>
        <?php foreach ($rows as $task): ?>
            <div class="task-row"><div><strong><?= e($task['title']) ?></strong><small><?= e($task['due'] ?? t('No due date')) ?></small></div>
                <?php if ($hasLessons): ?><form method="post" action="/tasks/<?= e($task['id']) ?>/done"><?= csrf_field() ?><input type="hidden" name="return_to" value="/"><button class="secondary"><?= e(t('Done')) ?></button></form>
                <?php else: ?><a href="/planner?view=tasks"><?= e(t('Open')) ?></a><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($group['key']==='exams'): foreach (array_slice($exams,0,4) as $exam): ?>
            <div class="task-row"><div><strong><?= e($exam['module']) ?></strong><small><?= e($exam['date']) ?><?= $exam['venue']!=='' ? ' · '.e($exam['venue']) : '' ?></small></div><a href="/uni?view=modules"><?= e(t('Open')) ?></a></div>
        <?php endforeach; endif; ?>
        <?php if (!$rows && ($group['key']!=='exams' || !$exams)): ?><p class="muted dashboard-empty"><?= e($group['empty']) ?></p><?php endif; ?>
    </section>
<?php endforeach; ?>
    <section class="today-category" data-today-category="notes" aria-labelledby="today-notes-title">
        <h4 id="today-notes-title"><?= e(t('Notes')) ?></h4>
        <?php foreach ($panelNotes as $note): ?>
            <div class="today-note"><strong><?= e($note['title']) ?></strong><?php if ($note['body']!==''): ?><p><?= e(mb_substr($note['body'],0,180)) ?><?= mb_strlen($note['body'])>180 ? '…' : '' ?></p><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if (!$panelNotes): ?><p class="muted dashboard-empty"><?= e(t('No notes yet.')) ?></p><?php endif; ?>
    </section>
</div>

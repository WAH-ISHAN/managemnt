<nav class="uni-add-actions" aria-label="<?= e(t('Add university records')) ?>">
<a class="btn secondary" href="/uni/calendar"><?= e(t('Lecture calendar')) ?></a>
<?php foreach(['module'=>t('Module'),'assignment'=>t('Assignment'),'exam'=>t('Exam'),'class'=>t('Timetable class')] as $actionType=>$actionLabel): ?><a class="btn secondary" href="/uni/add/<?= e($actionType) ?>">+ <?= e($actionLabel) ?></a><?php endforeach; ?>
</nav>

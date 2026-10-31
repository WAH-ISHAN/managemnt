<nav class="uni-add-actions" aria-label="Add university records">
<a class="btn secondary" href="/uni/calendar">Lecture calendar</a>
<?php foreach(['module'=>'Module','assignment'=>'Assignment','exam'=>'Exam','class'=>'Timetable class'] as $actionType=>$actionLabel): ?><a class="btn secondary" href="/uni/add/<?= e($actionType) ?>">+ <?= e($actionLabel) ?></a><?php endforeach; ?>
</nav>

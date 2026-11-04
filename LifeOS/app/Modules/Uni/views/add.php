<?php $names=['module'=>t('Add a module'),'assignment'=>t('Add an assignment'),'exam'=>t('Add an exam'),'class'=>t('Add a timetable class')]; $destinations=['module'=>'modules','assignment'=>'assignments','exam'=>'exams','class'=>'timetable']; $editing=isset($editingModule); if($editing) $names['module']='Edit a module'; $formAction=$editing ? '/uni/module/'.$editingModule['id'].'/edit' : '/uni/'.($type==='class' ? 'timetable' : $type); ?>
<div class="topline"><a href="/uni"><?= e(t('UNIVERSITY')) ?></a><span><?= e(t('One entry at a time.')) ?></span></div>
<header class="page-header"><div><h1><?= e($names[$type]) ?></h1><p class="muted"><?= e(t('Choose the entry you need. You can switch options without completing this form.')) ?></p></div><a class="btn secondary" href="/uni?view=<?= e($destinations[$type]) ?>"><?= e(t('View')) ?> <?= e($destinations[$type]==='modules' ? 'modules' : $destinations[$type]) ?></a></header>
<?php require ROOT.'/app/Core/views/notices.php'; require __DIR__.'/actions.php'; ?>
<section class="card uni-entry-card">
<form class="form-grid" method="post" action="<?= e($formAction) ?>"><?= csrf_field() ?>
<?php if($type==='module'): ?>
<label><?= e(t('Module name')) ?><input name="name" value="<?= e($editingModule['name'] ?? '') ?>" maxlength="150" required autofocus></label><label><?= e(t('Code (optional)')) ?><input name="code" value="<?= e($editingModule['code'] ?? '') ?>" maxlength="30"></label><label><?= e(t('Credits')) ?><input type="number" name="credits" min="0" max="100" value="<?= e($editingModule['credits'] ?? 0) ?>" required></label><label><?= e(t('Semester (optional)')) ?><input name="semester" value="<?= e($editingModule['semester'] ?? '') ?>" maxlength="100"></label>
<?php else: require __DIR__.'/module-picker.php'; ?>
<?php if($type==='assignment'): ?>
<label class="full"><?= e(t('Assignment title')) ?><input name="title" maxlength="200" required></label><label><?= e(t('Due date (optional)')) ?><input name="due_date" type="date"></label><label><?= e(t('Weight % (optional)')) ?><input name="weight" type="number" min="0" max="100" step="0.01"></label>
<?php elseif($type==='exam'): ?>
<label><?= e(t('Exam date')) ?><input name="date" type="date" required></label><label><?= e(t('Venue (optional)')) ?><input name="venue" maxlength="200"></label>
<?php else: ?>
<label><?= e(t('Day')) ?><select name="day"><?php foreach(UniService::DAYS as $key=>$day): ?><option value="<?= e($key) ?>"><?= e($day) ?></option><?php endforeach; ?></select></label><label><?= e(t('Start time')) ?><input name="start_time" type="time" required></label><label><?= e(t('End time')) ?><input name="end_time" type="time" required></label><label><?= e(t('Venue (optional)')) ?><input name="venue" maxlength="200"></label>
<?php endif; endif; ?>
<div class="full hero-actions"><button><?= $editing ? t('Save module') : e($names[$type]) ?></button><a href="/uni?view=<?= e($destinations[$type]) ?>"><?= e(t('Cancel')) ?></a></div>
</form></section>

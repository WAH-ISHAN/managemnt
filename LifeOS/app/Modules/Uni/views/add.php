<?php $names=['module'=>'Add a module','assignment'=>'Add an assignment','exam'=>'Add an exam','class'=>'Add a timetable class']; $destinations=['module'=>'modules','assignment'=>'assignments','exam'=>'exams','class'=>'timetable']; $editing=isset($editingModule); if($editing) $names['module']='Edit a module'; $formAction=$editing ? '/uni/module/'.$editingModule['id'].'/edit' : '/uni/'.($type==='class' ? 'timetable' : $type); ?>
<div class="topline"><a href="/uni">UNIVERSITY</a><span>One entry at a time.</span></div>
<header class="page-header"><div><h1><?= e($names[$type]) ?></h1><p class="muted">Choose the entry you need. You can switch options without completing this form.</p></div><a class="btn secondary" href="/uni?view=<?= e($destinations[$type]) ?>">View <?= e($destinations[$type]==='modules' ? 'modules' : $destinations[$type]) ?></a></header>
<?php require ROOT.'/app/Core/views/notices.php'; require __DIR__.'/actions.php'; ?>
<section class="card uni-entry-card">
<form class="form-grid" method="post" action="<?= e($formAction) ?>"><?= csrf_field() ?>
<?php if($type==='module'): ?>
<label>Module name<input name="name" value="<?= e($editingModule['name'] ?? '') ?>" maxlength="150" required autofocus></label><label>Code (optional)<input name="code" value="<?= e($editingModule['code'] ?? '') ?>" maxlength="30"></label><label>Credits<input type="number" name="credits" min="0" max="100" value="<?= e($editingModule['credits'] ?? 0) ?>" required></label><label>Semester (optional)<input name="semester" value="<?= e($editingModule['semester'] ?? '') ?>" maxlength="100"></label>
<?php else: require __DIR__.'/module-picker.php'; ?>
<?php if($type==='assignment'): ?>
<label class="full">Assignment title<input name="title" maxlength="200" required></label><label>Due date (optional)<input name="due_date" type="date"></label><label>Weight % (optional)<input name="weight" type="number" min="0" max="100" step="0.01"></label>
<?php elseif($type==='exam'): ?>
<label>Exam date<input name="date" type="date" required></label><label>Venue (optional)<input name="venue" maxlength="200"></label>
<?php else: ?>
<label>Day<select name="day"><?php foreach(UniService::DAYS as $key=>$day): ?><option value="<?= e($key) ?>"><?= e($day) ?></option><?php endforeach; ?></select></label><label>Start time<input name="start_time" type="time" required></label><label>End time<input name="end_time" type="time" required></label><label>Venue (optional)<input name="venue" maxlength="200"></label>
<?php endif; endif; ?>
<div class="full hero-actions"><button><?= $editing ? 'Save module' : e($names[$type]) ?></button><a href="/uni?view=<?= e($destinations[$type]) ?>">Cancel</a></div>
</form></section>

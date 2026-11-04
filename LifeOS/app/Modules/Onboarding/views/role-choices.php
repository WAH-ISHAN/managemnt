<?php
$selectedRoles??=[];
$roleFeatures=[
    'school'=>['Subjects and homework','School timetable and exams','Revision and learning goals'],
    'uni'=>['Modules, assignments and GPA','Lectures and study planning','Student budget and career preparation'],
    'employee'=>['Work priorities and projects','Work hours and responsibilities','Salary and personal finances'],
    'freelancer'=>['Client projects and deadlines','Income and expenses','Documents and websites'],
    'business'=>['Business projects and records','Income and expenses','Websites and administration'],
    'job_seeker'=>['Applications and interviews','Skills and learning goals','CV and certificates'],
];
?>
<p class="muted" id="role-help"><?= e(t('Select everything that applies. You can study and work at the same time.')) ?></p>
<div class="persona-grid" data-role-choices data-required-message="<?= e(t('Choose at least one role to continue.')) ?>" aria-describedby="role-help">
<?php foreach(PersonaService::personas() as $key=>$definition): ?>
<label class="persona-choice"><input type="checkbox" name="roles[]" value="<?= e($key) ?>" <?= in_array($key,$selectedRoles,true)?'checked':'' ?> data-role-features="<?= e(json_encode(array_map('t',$roleFeatures[$key]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)) ?>"><span><strong><?= e(t($definition['name'])) ?></strong><small><?= e($definition['description']) ?></small></span></label>
<?php endforeach; ?>
</div>
<div class="role-preview" data-role-preview hidden><strong><?= e(t('Your workspace will include')) ?></strong><ul data-role-feature-list></ul><p class="footnote"><?= e(t('Planner, focus, goals and reminders are shared across your roles.')) ?></p></div>

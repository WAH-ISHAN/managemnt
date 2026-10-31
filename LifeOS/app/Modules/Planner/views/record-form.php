<?php $editing=isset($record); $row=$record ?? []; $action='/planner/'.$formType.($editing ? '/'.$row['id'].'/edit' : ''); ?>
<form class="stack" action="<?= e($action) ?>" method="post"><?= csrf_field() ?>
<label>Title<input name="title" maxlength="200" value="<?= e($row['title'] ?? '') ?>" required></label>
<?php if ($formType==='habits'): ?>
<label>Notes<textarea name="notes" maxlength="2000" rows="2"><?= e($row['notes'] ?? '') ?></textarea></label>
<?php elseif ($formType==='routines'): ?>
<label>Time<input type="time" name="time" value="<?= e($row['time'] ?? '') ?>" required></label><fieldset><legend>Repeat on</legend><div class="export-links"><?php foreach (PlannerService::DAYS as $day=>$label): ?><label><input type="checkbox" name="days[]" value="<?= e($day) ?>" <?= in_array((string)$day,explode(',',$row['days'] ?? '1,2,3,4,5'),true) ? 'checked' : '' ?>><?= e($label) ?></label><?php endforeach; ?></div></fieldset>
<?php else: ?>
<label>Starts<input type="datetime-local" name="starts_at" value="<?= e(str_replace(' ','T',$row['starts_at'] ?? '')) ?>" required></label><label>Ends<input type="datetime-local" name="ends_at" value="<?= e(str_replace(' ','T',$row['ends_at'] ?? '')) ?>" required></label><label>Location<input name="location" maxlength="200" value="<?= e($row['location'] ?? '') ?>"></label><label>Reminder (minutes before)<input name="reminder_minutes" type="number" min="0" max="10080" value="<?= e($row['reminder_minutes'] ?? 15) ?>" required></label><label>Notes<textarea name="notes" rows="2" maxlength="2000"><?= e($row['notes'] ?? '') ?></textarea></label><label>Status<select name="status"><?php foreach (['scheduled'=>'Scheduled','done'=>'Done','cancelled'=>'Cancelled'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= ($row['status'] ?? 'scheduled')===$key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<?php endif; ?>
<?php if ($editing && $formType!=='appointments'): ?><label><input type="checkbox" name="active" value="1" <?= $row['active'] ? 'checked' : '' ?>>Active</label><?php endif; ?>
<button><?= $editing ? 'Save changes' : 'Add '.e(match($formType) {'habits'=>'habit','routines'=>'routine',default=>'appointment'}) ?></button></form>

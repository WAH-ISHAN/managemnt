<form method="post" action="<?= $workPath ?>/release" class="form-grid work-release-form">
<?= csrf_field() ?>
<?php if($releaseEditing): ?><input type="hidden" name="release_id" value="<?= e($releaseEditing['id']) ?>"><input type="hidden" name="version" value="<?= e($releaseEditing['version']) ?>"><?php endif; ?>
<label><?= e(t('Name')) ?><input name="name" maxlength="160" value="<?= e($releaseEditing['name']??'') ?>" placeholder="e.g. Semester 1 launch" required></label>
<label><?= e(t('Status')) ?><select name="status"><?php foreach(WorkReleaseService::STATUSES as $value=>$label): ?><option value="<?= $value ?>" <?= ($releaseEditing['status']??'planned')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label><?= e(t('Start date')) ?><input name="starts_on" type="date" value="<?= e($releaseEditing['starts_on']??'') ?>"></label><label><?= e(t('Release date')) ?><input name="release_on" type="date" value="<?= e($releaseEditing['release_on']??'') ?>"></label>
<label class="full"><?= e(t('Description')) ?><textarea name="description" maxlength="5000" rows="3"><?= e($releaseEditing['description']??'') ?></textarea></label>
<div class="full"><button class="secondary"><?= $releaseEditing?t('Save release'):t('Create release') ?></button></div>
</form>

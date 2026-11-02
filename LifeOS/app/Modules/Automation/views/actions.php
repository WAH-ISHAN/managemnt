<?php $first=$configuredActions[0] ?? ['type'=>'morning_brief']; ?>
<label>What should happen?<select name="actions[0][type]" required><?php foreach (AutomationService::ACTIONS as $key=>$label): ?><option value="<?= e($key) ?>" <?= $first['type']===$key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<p class="footnote">Use Weekly for a weekly report, Monthly for a monthly summary, and Deadline ladder for countdown reminders.</p>
<label>Custom reminder title (custom action only)<input name="actions[0][title]" maxlength="150" value="<?= e($first['title'] ?? '') ?>"></label>
<label>Custom reminder message<textarea name="actions[0][body]" maxlength="1000" rows="2"><?= e($first['body'] ?? '') ?></textarea></label>
<details <?= count($configuredActions)>1 ? 'open' : '' ?>><summary>Additional actions (optional)</summary>
<p class="footnote">Up to five different actions run in order. Choose “No additional action” to remove a slot.</p>
<?php for ($index=1;$index<5;$index++): $extra=$configuredActions[$index] ?? ['type'=>'']; ?>
<fieldset class="choice-fieldset"><legend>Action <?= $index+1 ?></legend>
<label>Action<select name="actions[<?= $index ?>][type]"><option value="">No additional action</option><?php foreach (AutomationService::ACTIONS as $key=>$label): ?><option value="<?= e($key) ?>" <?= $extra['type']===$key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<details <?= $extra['type']==='notify' ? 'open' : '' ?>><summary>Custom reminder text</summary>
<label>Title (custom action only)<input name="actions[<?= $index ?>][title]" maxlength="150" value="<?= e($extra['title'] ?? '') ?>"></label>
<label>Message<textarea name="actions[<?= $index ?>][body]" maxlength="1000" rows="2"><?= e($extra['body'] ?? '') ?></textarea></label>
</details></fieldset>
<?php endfor; ?>
</details>

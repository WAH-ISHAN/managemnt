<div class="topline"><span><?= e(t('SMALL BUSINESS')) ?></span><a href="/small-business"><?= e(t('All projects')) ?></a></div>
<header class="page-header"><div><h1><?= e($project['name']) ?></h1><p class="muted"><?= e($project['client']) ?> &middot; <?= e($project['status']) ?> <?= e(t('· Hourly rate')) ?> <?= money($project['hourly_rate']) ?></p></div></header>
<?php require ROOT.'/app/Core/views/notices.php'; ?>
<?php if ($project['notes']!==''): ?><section class="card"><h2><?= e(t('Project notes')) ?></h2><p><?= nl2br(e($project['notes'])) ?></p></section><?php endif; ?>
<?php if ($project['status']==='active'): ?>
<section class="card"><h2><?= e(t('Add a record')) ?></h2><form class="form-grid" method="post" action="/small-business/project/<?= e($project['id']) ?>/entry"><?= csrf_field() ?>
<label><?= e(t('Date')) ?><input name="date" type="date" value="<?= e(ui_date('Y-m-d')) ?>" required></label>
<label><?= e(t('Type')) ?><select name="kind"><option value="time"><?= e(t('Time (hours)')) ?></option><option value="income"><?= e(t('Income (Rs.)')) ?></option><option value="cost"><?= e(t('Cost (Rs.)')) ?></option></select></label>
<label><?= e(t('Amount / hours')) ?><input name="quantity" type="number" min="0.01" step="0.01" required></label>
<label><?= e(t('Note')) ?><input name="note" maxlength="1000"></label><div class="full"><button><?= e(t('Save record')) ?></button></div>
</form></section>
<?php endif; ?>
<section class="card"><h2><?= e(t('Records')) ?></h2><p class="muted"><?= e(t('Time is tracked separately from cash. Income and costs here do not change personal account balances.')) ?></p>
<?php foreach ($entries as $row): ?><div class="simple-row"><span><strong><?= e(ucfirst($row['kind'])) ?></strong><small><?= e($row['date']) ?> &middot; <?= e($row['note']) ?></small></span><strong><?= $row['kind']==='time' ? e(number_format($row['quantity'],2)).' hours' : money($row['quantity']) ?></strong>
<form method="post" action="/small-business/project/<?= e($project['id']) ?>/entry/<?= e($row['id']) ?>/delete" data-confirm="Remove this project record and recalculate its totals?"><?= csrf_field() ?><button class="quiet"><?= e(t('Remove')) ?></button></form></div><?php endforeach; ?>
<?php if (!$entries): ?><p class="muted"><?= e(t('No records yet.')) ?></p><?php endif; ?></section>
<?php if ($project['status']==='active'): ?><form method="post" action="/small-business/project/<?= e($project['id']) ?>/close" data-confirm="Close this project? Existing records will be kept."><?= csrf_field() ?><button class="secondary"><?= e(t('Close project')) ?></button></form><?php endif; ?>
<div class="topline"><span>SMALL BUSINESS</span><a href="/small-business">All projects</a></div>
<header class="page-header"><div><h1><?= e($project['name']) ?></h1><p class="muted"><?= e($project['client']) ?> &middot; <?= e($project['status']) ?> &middot; Hourly rate <?= money($project['hourly_rate']) ?></p></div></header>
<?php require ROOT.'/app/Core/views/notices.php'; ?>
<?php if ($project['notes']!==''): ?><section class="card"><h2>Project notes</h2><p><?= nl2br(e($project['notes'])) ?></p></section><?php endif; ?>
<?php if ($project['status']==='active'): ?>
<section class="card"><h2>Add a record</h2><form class="form-grid" method="post" action="/small-business/project/<?= e($project['id']) ?>/entry"><?= csrf_field() ?>
<label>Date<input name="date" type="date" value="<?= e(date('Y-m-d')) ?>" required></label>
<label>Type<select name="kind"><option value="time">Time (hours)</option><option value="income">Income (Rs.)</option><option value="cost">Cost (Rs.)</option></select></label>
<label>Amount / hours<input name="quantity" type="number" min="0.01" step="0.01" required></label>
<label>Note<input name="note" maxlength="1000"></label><div class="full"><button>Save record</button></div>
</form></section>
<?php endif; ?>
<section class="card"><h2>Records</h2><p class="muted">Time is tracked separately from cash. Income and costs here do not change personal account balances.</p>
<?php foreach ($entries as $row): ?><div class="simple-row"><span><strong><?= e(ucfirst($row['kind'])) ?></strong><small><?= e($row['date']) ?> &middot; <?= e($row['note']) ?></small></span><strong><?= $row['kind']==='time' ? e(number_format($row['quantity'],2)).' hours' : money($row['quantity']) ?></strong>
<form method="post" action="/small-business/project/<?= e($project['id']) ?>/entry/<?= e($row['id']) ?>/delete" data-confirm="Remove this project record and recalculate its totals?"><?= csrf_field() ?><button class="quiet">Remove</button></form></div><?php endforeach; ?>
<?php if (!$entries): ?><p class="muted">No records yet.</p><?php endif; ?></section>
<?php if ($project['status']==='active'): ?><form method="post" action="/small-business/project/<?= e($project['id']) ?>/close" data-confirm="Close this project? Existing records will be kept."><?= csrf_field() ?><button class="secondary">Close project</button></form><?php endif; ?>
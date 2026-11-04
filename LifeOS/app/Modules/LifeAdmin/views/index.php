<div class="topline"><span><?= e(t('PERSONAL ESSENTIALS')) ?></span><span><?= e(t('Only records you enter, stored locally.')) ?></span></div>
<header class="page-header"><div><h1><?= e(t('Dates and money to remember.')) ?></h1><p class="muted"><?= e(t('Track renewals, important expiries, and money between people.')) ?></p></div></header>
<?php require ROOT.'/app/Core/views/notices.php'; ?>
<nav class="tabs" aria-label="<?= e(t('Personal essentials')) ?>"><a href="#expiries"><?= e(t('Expiries')) ?></a><a href="#subscriptions"><?= e(t('Subscriptions')) ?></a><a href="#loans"><?= e(t('Lend & borrow')) ?></a></nav>
<section class="card" id="expiries">
<h2><?= e(t('Expiries')) ?></h2>
<form class="form-grid" method="post" action="/life-admin/expiry"><?= csrf_field() ?>
<label><?= e(t('Item')) ?><input name="title" maxlength="200" required placeholder="<?= e(t('Passport, warranty, insurance...')) ?>"></label>
<label><?= e(t('Type')) ?><select name="kind"><option value="<?= e('Document') ?>"><?= e(t('Document')) ?></option><option value="<?= e('Insurance') ?>"><?= e(t('Insurance')) ?></option><option value="<?= e('Warranty') ?>"><?= e(t('Warranty')) ?></option><option value="<?= e('Vehicle') ?>"><?= e(t('Vehicle')) ?></option><option value="<?= e('Other') ?>"><?= e(t('Other')) ?></option></select></label>
<label><?= e(t('Expiry date')) ?><input type="date" name="expires_on" required></label>
<label><?= e(t('Remind this many days before')) ?><input type="number" name="remind_days" min="0" max="365" value="30" required></label>
<label class="full"><?= e(t('Notes')) ?><input name="notes" maxlength="2000"></label><div class="full"><button><?= e(t('Save expiry')) ?></button></div>
</form>
<?php foreach ($expiries as $row): $days=(int)((new DateTimeImmutable('today'))->diff(new DateTimeImmutable($row['expires_on']))->format('%r%a')); ?>
<article class="lesson-entry">
<div class="section-heading"><div><h3><?= e($row['title']) ?></h3><small><?= e($row['kind']) ?> &middot; <?= e($row['expires_on']) ?><?= $row['status']==='done' ? ' &middot; Done' : ($days<0 ? ' &middot; Expired' : ($days<=$row['remind_days'] ? ' &middot; Due soon' : '')) ?></small></div>
<?php if ($row['status']==='active'): ?><form method="post" action="/life-admin/expiry/<?= e($row['id']) ?>/done"><?= csrf_field() ?><button class="secondary"><?= e(t('Done')) ?></button></form><?php endif; ?></div>
<?php if ($row['notes']!==''): ?><p><?= nl2br(e($row['notes'])) ?></p><?php endif; ?>
<details><summary><?= e(t('Update expiry date')) ?></summary><form class="inline" method="post" action="/life-admin/expiry/<?= e($row['id']) ?>/renew"><?= csrf_field() ?><label><?= e(t('New expiry date')) ?><input type="date" name="expires_on" value="<?= e($row['expires_on']) ?>" required></label><button class="secondary"><?= e(t('Save date')) ?></button></form></details>
</article><?php endforeach; ?>
<?php if (!$expiries): ?><p class="muted"><?= e(t('No expiries saved yet.')) ?></p><?php endif; ?>
</section>
<section class="card" id="subscriptions">
<h2><?= e(t('Subscriptions')) ?></h2>
<form class="form-grid" method="post" action="/life-admin/subscription"><?= csrf_field() ?>
<label><?= e(t('Name')) ?><input name="name" maxlength="200" required></label>
<label><?= e(t('Amount (Rs.)')) ?><input type="number" name="amount" min="0.01" step="0.01" required></label>
<label><?= e(t('Billing')) ?><select name="cadence"><option value="monthly"><?= e(t('Monthly')) ?></option><option value="yearly"><?= e(t('Yearly')) ?></option></select></label>
<label><?= e(t('Next renewal')) ?><input type="date" name="renews_on" required></label>
<label class="full"><?= e(t('Notes')) ?><input name="notes" maxlength="2000"></label><div class="full"><button><?= e(t('Save subscription')) ?></button></div>
</form>
<?php $monthly=0; foreach ($subscriptions as $row) if ($row['status']==='active') $monthly+=(float)$row['amount']/($row['cadence']==='yearly'?12:1); ?>
<p><strong><?= e(t('Active monthly equivalent:')) ?> <?= money($monthly) ?></strong></p>
<p class="muted"><?= e(t('Renewal dates are updated by you. LifeOS does not charge or cancel a subscription with its provider.')) ?></p>
<?php foreach ($subscriptions as $row): ?><article class="lesson-entry">
<div class="section-heading"><div><h3><?= e($row['name']) ?> &middot; <?= money($row['amount']) ?></h3><small><?= e($row['cadence']) ?> &middot; <?= e($row['renews_on']) ?> &middot; <?= e($row['status']) ?></small></div>
<?php if ($row['status']==='active'): ?><form method="post" action="/life-admin/subscription/<?= e($row['id']) ?>/cancel"><?= csrf_field() ?><button class="secondary"><?= e(t('Mark cancelled')) ?></button></form><?php endif; ?></div>
<?php if ($row['notes']!==''): ?><p><?= nl2br(e($row['notes'])) ?></p><?php endif; ?>
<details><summary><?= $row['status']==='cancelled' ? t('Reactivate with a renewal date') : t('Update next renewal') ?></summary><form class="inline" method="post" action="/life-admin/subscription/<?= e($row['id']) ?>/renew"><?= csrf_field() ?><label><?= e(t('Next renewal')) ?><input type="date" name="renews_on" value="<?= e($row['renews_on']) ?>" required></label><button class="secondary"><?= e(t('Save date')) ?></button></form></details>
</article><?php endforeach; ?>
<?php if (!$subscriptions): ?><p class="muted"><?= e(t('No subscriptions saved yet.')) ?></p><?php endif; ?>
</section>
<section class="card" id="loans">
<h2><?= e(t('Lend & borrow')) ?></h2><p class="muted"><?= e(t('A personal ledger. These entries do not move account balances.')) ?></p>
<form class="form-grid" method="post" action="/life-admin/loan"><?= csrf_field() ?>
<label><?= e(t('Person')) ?><input name="person" maxlength="200" required></label>
<label><?= e(t('Direction')) ?><select name="direction"><option value="lent"><?= e(t('I lent money')) ?></option><option value="borrowed"><?= e(t('I borrowed money')) ?></option></select></label>
<label><?= e(t('Amount (Rs.)')) ?><input type="number" name="amount" min="0.01" step="0.01" required></label>
<label><?= e(t('Due date (optional)')) ?><input type="date" name="due_on"></label>
<label class="full"><?= e(t('Notes')) ?><input name="notes" maxlength="2000"></label><div class="full"><button><?= e(t('Save entry')) ?></button></div>
</form>
<?php foreach ($loans as $row): $remaining=max(0,round(((float)$row['original_amount']-(float)$row['paid'])*100)/100); ?>
<article class="lesson-entry"><h3><?= e($row['person']) ?> &middot; <?= e($row['direction']) ?></h3>
<p><?= money($remaining) ?> <?= e(t('remaining of')) ?> <?= money($row['original_amount']) ?><?= $row['due_on'] ? ' &middot; Due '.e($row['due_on']) : '' ?><?= $remaining>0 && $row['due_on'] && $row['due_on']<ui_date('Y-m-d') ? ' &middot; Overdue' : '' ?></p>
<?php if ($row['notes']!==''): ?><p><?= nl2br(e($row['notes'])) ?></p><?php endif; ?>
<?php if ($remaining>0): ?><form class="inline" method="post" action="/life-admin/loan/<?= e($row['id']) ?>/payment"><?= csrf_field() ?><label><?= e(t('Payment (Rs.)')) ?><input type="number" name="amount" min="0.01" max="<?= e($remaining) ?>" step="0.01" required></label><label><?= e(t('Date')) ?><input type="date" name="paid_on" value="<?= e(ui_date('Y-m-d')) ?>" required></label><button class="secondary"><?= e(t('Record payment')) ?></button></form><?php endif; ?>
<?php if ((float)$row['paid']>0): ?><details><summary><?= e(t('Payment history ·')) ?> <?= money($row['paid']) ?> <?= e(t('paid')) ?></summary>
<?php foreach ($payments as $payment): if ((int)$payment['loan_id']!==(int)$row['id']) continue; ?><div class="simple-row"><span><?= e($payment['paid_on']) ?> &middot; <?= money($payment['amount']) ?></span><form method="post" action="/life-admin/loan/<?= e($row['id']) ?>/payment/<?= e($payment['id']) ?>/delete" data-confirm="Remove this payment and add its amount back to the remaining balance?"><?= csrf_field() ?><button class="quiet"><?= e(t('Remove payment')) ?></button></form></div><?php endforeach; ?>
</details><?php endif; ?>
</article><?php endforeach; ?>
<?php if (!$loans): ?><p class="muted"><?= e(t('No lending or borrowing saved yet.')) ?></p><?php endif; ?>
</section>
<div class="topline"><span>PERSONAL ESSENTIALS</span><span>Only records you enter, stored locally.</span></div>
<header class="page-header"><div><h1>Dates and money to remember.</h1><p class="muted">Track renewals, important expiries, and money between people.</p></div></header>
<?php require ROOT.'/app/Core/views/notices.php'; ?>
<nav class="tabs" aria-label="Personal essentials"><a href="#expiries">Expiries</a><a href="#subscriptions">Subscriptions</a><a href="#loans">Lend &amp; borrow</a></nav>
<section class="card" id="expiries">
<h2>Expiries</h2>
<form class="form-grid" method="post" action="/life-admin/expiry"><?= csrf_field() ?>
<label>Item<input name="title" maxlength="200" required placeholder="Passport, warranty, insurance..."></label>
<label>Type<select name="kind"><option>Document</option><option>Insurance</option><option>Warranty</option><option>Vehicle</option><option>Other</option></select></label>
<label>Expiry date<input type="date" name="expires_on" required></label>
<label>Remind this many days before<input type="number" name="remind_days" min="0" max="365" value="30" required></label>
<label class="full">Notes<input name="notes" maxlength="2000"></label><div class="full"><button>Save expiry</button></div>
</form>
<?php foreach ($expiries as $row): $days=(int)((new DateTimeImmutable('today'))->diff(new DateTimeImmutable($row['expires_on']))->format('%r%a')); ?>
<article class="lesson-entry">
<div class="section-heading"><div><h3><?= e($row['title']) ?></h3><small><?= e($row['kind']) ?> &middot; <?= e($row['expires_on']) ?><?= $row['status']==='done' ? ' &middot; Done' : ($days<0 ? ' &middot; Expired' : ($days<=$row['remind_days'] ? ' &middot; Due soon' : '')) ?></small></div>
<?php if ($row['status']==='active'): ?><form method="post" action="/life-admin/expiry/<?= e($row['id']) ?>/done"><?= csrf_field() ?><button class="secondary">Done</button></form><?php endif; ?></div>
<?php if ($row['notes']!==''): ?><p><?= nl2br(e($row['notes'])) ?></p><?php endif; ?>
<details><summary>Update expiry date</summary><form class="inline" method="post" action="/life-admin/expiry/<?= e($row['id']) ?>/renew"><?= csrf_field() ?><label>New expiry date<input type="date" name="expires_on" value="<?= e($row['expires_on']) ?>" required></label><button class="secondary">Save date</button></form></details>
</article><?php endforeach; ?>
<?php if (!$expiries): ?><p class="muted">No expiries saved yet.</p><?php endif; ?>
</section>
<section class="card" id="subscriptions">
<h2>Subscriptions</h2>
<form class="form-grid" method="post" action="/life-admin/subscription"><?= csrf_field() ?>
<label>Name<input name="name" maxlength="200" required></label>
<label>Amount (Rs.)<input type="number" name="amount" min="0.01" step="0.01" required></label>
<label>Billing<select name="cadence"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></label>
<label>Next renewal<input type="date" name="renews_on" required></label>
<label class="full">Notes<input name="notes" maxlength="2000"></label><div class="full"><button>Save subscription</button></div>
</form>
<?php $monthly=0; foreach ($subscriptions as $row) if ($row['status']==='active') $monthly+=(float)$row['amount']/($row['cadence']==='yearly'?12:1); ?>
<p><strong>Active monthly equivalent: <?= money($monthly) ?></strong></p>
<p class="muted">Renewal dates are updated by you. LifeOS does not charge or cancel a subscription with its provider.</p>
<?php foreach ($subscriptions as $row): ?><article class="lesson-entry">
<div class="section-heading"><div><h3><?= e($row['name']) ?> &middot; <?= money($row['amount']) ?></h3><small><?= e($row['cadence']) ?> &middot; <?= e($row['renews_on']) ?> &middot; <?= e($row['status']) ?></small></div>
<?php if ($row['status']==='active'): ?><form method="post" action="/life-admin/subscription/<?= e($row['id']) ?>/cancel"><?= csrf_field() ?><button class="secondary">Mark cancelled</button></form><?php endif; ?></div>
<?php if ($row['notes']!==''): ?><p><?= nl2br(e($row['notes'])) ?></p><?php endif; ?>
<details><summary><?= $row['status']==='cancelled' ? 'Reactivate with a renewal date' : 'Update next renewal' ?></summary><form class="inline" method="post" action="/life-admin/subscription/<?= e($row['id']) ?>/renew"><?= csrf_field() ?><label>Next renewal<input type="date" name="renews_on" value="<?= e($row['renews_on']) ?>" required></label><button class="secondary">Save date</button></form></details>
</article><?php endforeach; ?>
<?php if (!$subscriptions): ?><p class="muted">No subscriptions saved yet.</p><?php endif; ?>
</section>
<section class="card" id="loans">
<h2>Lend &amp; borrow</h2><p class="muted">A personal ledger. These entries do not move account balances.</p>
<form class="form-grid" method="post" action="/life-admin/loan"><?= csrf_field() ?>
<label>Person<input name="person" maxlength="200" required></label>
<label>Direction<select name="direction"><option value="lent">I lent money</option><option value="borrowed">I borrowed money</option></select></label>
<label>Amount (Rs.)<input type="number" name="amount" min="0.01" step="0.01" required></label>
<label>Due date (optional)<input type="date" name="due_on"></label>
<label class="full">Notes<input name="notes" maxlength="2000"></label><div class="full"><button>Save entry</button></div>
</form>
<?php foreach ($loans as $row): $remaining=max(0,round(((float)$row['original_amount']-(float)$row['paid'])*100)/100); ?>
<article class="lesson-entry"><h3><?= e($row['person']) ?> &middot; <?= e($row['direction']) ?></h3>
<p><?= money($remaining) ?> remaining of <?= money($row['original_amount']) ?><?= $row['due_on'] ? ' &middot; Due '.e($row['due_on']) : '' ?><?= $remaining>0 && $row['due_on'] && $row['due_on']<date('Y-m-d') ? ' &middot; Overdue' : '' ?></p>
<?php if ($row['notes']!==''): ?><p><?= nl2br(e($row['notes'])) ?></p><?php endif; ?>
<?php if ($remaining>0): ?><form class="inline" method="post" action="/life-admin/loan/<?= e($row['id']) ?>/payment"><?= csrf_field() ?><label>Payment (Rs.)<input type="number" name="amount" min="0.01" max="<?= e($remaining) ?>" step="0.01" required></label><label>Date<input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>" required></label><button class="secondary">Record payment</button></form><?php endif; ?>
<?php if ((float)$row['paid']>0): ?><details><summary>Payment history &middot; <?= money($row['paid']) ?> paid</summary>
<?php foreach ($payments as $payment): if ((int)$payment['loan_id']!==(int)$row['id']) continue; ?><div class="simple-row"><span><?= e($payment['paid_on']) ?> &middot; <?= money($payment['amount']) ?></span><form method="post" action="/life-admin/loan/<?= e($row['id']) ?>/payment/<?= e($payment['id']) ?>/delete" data-confirm="Remove this payment and add its amount back to the remaining balance?"><?= csrf_field() ?><button class="quiet">Remove payment</button></form></div><?php endforeach; ?>
</details><?php endif; ?>
</article><?php endforeach; ?>
<?php if (!$loans): ?><p class="muted">No lending or borrowing saved yet.</p><?php endif; ?>
</section>
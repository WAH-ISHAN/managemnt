<section id="plan-preview" class="card" aria-live="polite">
<?php if (!$math): ?><h2><?= e(t('Your monthly plan preview')) ?></h2><p class="muted"><?= e($problem ?: t('Enter your income and expected costs to see an achievable plan.')) ?></p><?php else: ?>
<div class="section-heading"><h2><?= e(t('After your planned allocations')) ?></h2><span class="badge"><?= e(t('Planning estimate')) ?></span></div>
<div class="hero-number <?= $math['affordable'] ? '' : 'error-text' ?>"><?= money($math['available']) ?></div>
<p class="muted"><?= $math['affordable'] ? t('Unassigned money after essential costs, savings and investments.') : t('Your plan is overcommitted. Reduce allocations or expected costs before using it.') ?></p>
<div class="simple-row"><span><?= e(t('Bills, loans & living')) ?></span><strong><?= money($math['essentials']) ?></strong></div><div class="simple-row"><span><?= e(t('Savings')) ?></span><strong><?= money($math['savings']) ?></strong></div><div class="simple-row"><span><?= e(t('Investments')) ?></span><strong><?= money($math['investments']) ?></strong></div>
<h3><?= e(t('A savings target that fits this plan')) ?></h3><p><strong><?= money($math['suggested_savings']) ?> <?= e(t('/ month ·')) ?> <?= e(number_format($math['suggested_percent'],2)) ?>%</strong></p>
<p class="footnote"><?= e(t('Transparent planning rule: take income minus essential costs and your proposed investment allocation, then reserve half of the positive remainder for savings. The other half stays flexible. If no money remains, the suggested savings amount is zero. You choose the final rate; it does not increase automatically.')) ?></p>
<?php endif; ?></section>

<section id="plan-preview" class="card" aria-live="polite">
<?php if (!$math): ?><h2>Your monthly plan preview</h2><p class="muted"><?= e($problem ?: 'Enter your income and expected costs to see an achievable plan.') ?></p><?php else: ?>
<div class="section-heading"><h2>After your planned allocations</h2><span class="badge">Planning estimate</span></div>
<div class="hero-number <?= $math['affordable'] ? '' : 'error-text' ?>"><?= money($math['available']) ?></div>
<p class="muted"><?= $math['affordable'] ? 'Unassigned money after essential costs, savings and investments.' : 'Your plan is overcommitted. Reduce allocations or expected costs before using it.' ?></p>
<div class="simple-row"><span>Bills, loans & living</span><strong><?= money($math['essentials']) ?></strong></div><div class="simple-row"><span>Savings</span><strong><?= money($math['savings']) ?></strong></div><div class="simple-row"><span>Investments</span><strong><?= money($math['investments']) ?></strong></div>
<h3>A savings target that fits this plan</h3><p><strong><?= money($math['suggested_savings']) ?> / month · <?= e(number_format($math['suggested_percent'],2)) ?>%</strong></p>
<p class="footnote">Transparent planning rule: take income minus essential costs and your proposed investment allocation, then reserve half of the positive remainder for savings. The other half stays flexible. If no money remains, the suggested savings amount is zero. You choose the final rate; it does not increase automatically.</p>
<?php endif; ?></section>

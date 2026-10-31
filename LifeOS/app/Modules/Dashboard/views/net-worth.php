<div class="topline"><span>WHY THIS NUMBER?</span><a href="/">Today</a></div><header class="page-header"><div><h1>Recorded net worth</h1><p class="muted">Account balances + active asset values - outstanding planned debt. Values reflect only records saved in LifeOS.</p></div></header>
<?php $cash=array_sum(array_column($accounts,'balance')); $assetTotal=0; foreach ($assets as $asset) if ($asset['status']==='active') $assetTotal+=(float)$asset['current']; ?>
<section class="card">
<div class="simple-row"><strong>Accounts</strong><strong><?= money($cash) ?></strong></div>
<?php foreach ($accounts as $account): ?><div class="simple-row"><a href="/salary"><?= e($account['name']) ?><small><?= e($account['kind']) ?></small></a><span><?= money($account['balance']) ?></span></div><?php endforeach; ?>
<p class="footnote">Balances come from saved income allocations, expenses and linked asset transfers.</p>
<div class="simple-row"><strong>Active assets</strong><strong><?= money($assetTotal) ?></strong></div>
<?php foreach ($assets as $asset): if ($asset['status']!=='active') continue; ?><div class="simple-row"><a href="/investments/<?= e($asset['id']) ?>"><?= e($asset['name']) ?></a><span><?= money($asset['current']) ?></span></div><?php endforeach; ?>
<p class="footnote">Asset values use deposits and withdrawals up to today, with each valuation replacing the value on its date. Open an asset to see its entries.</p>
<div class="simple-row"><strong>Outstanding planned debt</strong><strong>-<?= money($debt) ?></strong></div>
<?php foreach ($debts ?? [] as $item): ?><div class="simple-row"><a href="/salary/calendar"><?= e($item['title']) ?><small>Due <?= e($item['due_date']) ?></small></a><span>-<?= money($item['outstanding']) ?></span></div><?php endforeach; ?>
<p class="footnote">Personal lend/borrow records and the separate Small business ledger are excluded from this formula.</p>
<div class="simple-row"><strong>Recorded net worth</strong><strong><?= money($cash+$assetTotal-$debt) ?></strong></div>
</section>



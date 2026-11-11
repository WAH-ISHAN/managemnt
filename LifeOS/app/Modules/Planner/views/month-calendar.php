<?php
$month=$calendar['first']; $start=$month->modify('-'.((int)$month->format('N')-1).' days');
$next=$month->modify('+1 month')->format('Y-m-d'); $previous=$month->modify('-1 month')->format('Y-m-d');
?>
<link rel="stylesheet" href="/assets/planner-calendar.css">
<section class="card"><div class="section-heading"><h2><?= e(ui_date('F Y',$month->getTimestamp())) ?></h2><nav class="lifeos-month-navigation" aria-label="<?= e(t('Calendar navigation')) ?>"><a href="/planner?view=calendar&amp;date=<?= e($previous) ?>" aria-label="<?= e(t('Previous month')) ?>">&larr; <?= e(t('Previous')) ?></a><a href="/planner?view=calendar&amp;date=<?= e(date('Y-m-d')) ?>"><?= e(t('Today')) ?></a><a href="/planner?view=calendar&amp;date=<?= e($next) ?>" aria-label="<?= e(t('Next month')) ?>"><?= e(t('Next')) ?> &rarr;</a></nav></div>
<p class="muted"><?= e(t('Add renewals, events and exams in Appointments. Dated tasks appear here too.')) ?> <a href="/planner?view=appointments&amp;date=<?= e($date) ?>"><?= e(t('Add an event')) ?></a></p>
<table class="lifeos-month-grid"><caption class="sr-only"><?= e(ui_date('F Y',$month->getTimestamp())) ?></caption><thead><tr>
<?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday): ?><th scope="col"><?= e(t($weekday)) ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php for ($week=0;$week<6;$week++): ?><tr><?php for ($weekday=0;$weekday<7;$weekday++): $day=$start->modify('+'.($week*7+$weekday).' days'); $value=$day->format('Y-m-d'); $entries=$calendar['items'][$value] ?? []; ?>
<td class="lifeos-month-day <?= $day->format('m')===$month->format('m') ? '' : 'lifeos-month-outside' ?>"><a class="lifeos-calendar-date" href="/planner?date=<?= e($value) ?>" aria-label="<?= e(ui_date('l, j F Y',$day->getTimestamp())) ?>" <?= $value===date('Y-m-d') ? 'aria-current="date"' : '' ?>><?= e($day->format('j')) ?></a><?php foreach ($entries as $entry): $label=t($entry['kind']).': '.$entry['title']; ?><a class="lifeos-month-entry" href="<?= e($entry['url']) ?>" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"><span><small><?= e(t($entry['kind'])) ?></small> <?= e($entry['title']) ?></span></a><?php endforeach; ?></td>
<?php endfor; ?></tr><?php endfor; ?></tbody></table>
<div class="lifeos-calendar-agenda"><h3><?= e(t('Schedule')) ?></h3><?php if (!$calendar['items']): ?><p class="muted"><?= e(t('No scheduled items.')) ?></p><?php endif; ?><?php $agenda=$calendar['items']; ksort($agenda); foreach ($agenda as $day=>$entries): ?><div><h4><?= e(ui_date('D, j M',strtotime($day))) ?></h4><?php foreach ($entries as $entry): ?><a class="lifeos-agenda-entry" href="<?= e($entry['url']) ?>"><small><?= e(t($entry['kind'])) ?></small><span><?= e($entry['title']) ?></span></a><?php endforeach; ?></div><?php endforeach; ?></div>
</section>

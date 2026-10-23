<?php
declare(strict_types=1);
$people = rows("SELECT id,name,email,role,created_at FROM users ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, id");
$grants = rows('SELECT user_id,module FROM grants');
$enabled = [];
foreach ($grants as $grant) $enabled[(int)$grant['user_id']][$grant['module']] = true;
$modules = ['calendar'=>'Calendar','exams'=>'Exams','targets'=>'Targets','tasks'=>'Daily tasks','salary'=>'Salary'];
?>
<div class="page-heading"><span class="eyebrow">CONTROL CENTER</span><h1>Admin center</h1><p>Give each person access to the modules they need.</p></div>
<section class="glass list-box"><div class="section-head"><h2>People and access</h2><span class="muted"><?= count($people) ?> accounts</span></div>
<?php foreach ($people as $person): ?><div class="admin-person"><div class="person-heading"><span class="avatar"><?= h(mb_strtoupper(mb_substr($person['name'], 0, 1))) ?></span><div><strong><?= h($person['name']) ?></strong><small><?= h($person['email']) ?></small></div><span class="badge <?= $person['role'] === 'admin' ? 'done' : 'open' ?>"><?= h(ucfirst($person['role'])) ?></span></div>
<?php if ($person['role'] === 'user'): ?><div class="grant-grid"><?php foreach ($modules as $key => $name): $has = !empty($enabled[(int)$person['id']][$key]); ?><form method="post" class="grant-control"><?php fields('admin','grant'); ?><input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>"><input type="hidden" name="module" value="<?= h($key) ?>"><input type="hidden" name="enabled" value="<?= $has ? '0' : '1' ?>"><span><?= h($name) ?></span><button class="toggle <?= $has ? 'on' : '' ?>" type="submit" aria-label="<?= $has ? 'Lock' : 'Unlock' ?> <?= h($name) ?> for <?= h($person['name']) ?>" aria-pressed="<?= $has ? 'true' : 'false' ?>"><span></span></button></form><?php endforeach; ?></div><?php else: ?><p class="muted">Full access</p><?php endif; ?></div><?php endforeach; ?></section>

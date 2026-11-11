<?php
/** @var array<string, array<string, string>> $navigationGroups */
$activeGroup = null;
foreach ($navigationGroups as $group => $items) {
    if (array_key_exists($activeKey, $items)) { $activeGroup = $group; break; }
}
?>
<nav class="workspace-navigation" aria-label="<?= e(t($navigationLabel)) ?>">
    <?php foreach ($navigationGroups as $group => $items): ?>
    <details class="workspace-navigation__group" name="workspace-tools" <?= $group === $activeGroup ? 'open' : '' ?>>
        <summary><?= e(t($group)) ?><span class="workspace-navigation__count"><?= count($items) ?></span></summary>
        <div class="workspace-navigation__items">
            <?php foreach ($items as $key => $label): ?><a href="<?= e($navigationUrl($key)) ?>" <?= $key === $activeKey ? 'aria-current="page"' : '' ?>><?= e(t($label)) ?></a><?php endforeach; ?>
        </div>
    </details>
    <?php endforeach; ?>
</nav>

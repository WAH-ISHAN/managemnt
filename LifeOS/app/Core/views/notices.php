<?php if ($message=flash()): ?><div class="notice" role="status"><?= e($message) ?></div><?php endif; ?>
<?php if (!empty($backupWarning)): ?><div class="notice warning" role="alert"><?= e($backupWarning) ?></div><?php endif; ?>

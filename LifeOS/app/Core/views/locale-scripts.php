<?php $uiLanguage=LocaleService::language(); ?>
<?php if ($uiLanguage!=='en'): ?><script src="/assets/locale-<?= e($uiLanguage) ?>.js" defer></script><?php endif; ?>
<script src="/assets/i18n.js" defer></script>

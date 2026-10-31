<?php
declare(strict_types=1);
final class View {
    private static array $shared = [];
    public static function share(string $key, mixed $value): void { self::$shared[$key] = $value; }
    public static function render(string $template, array $data = [], string $layout = 'layout'): string {
        $render = static function (string $file, array $vars): string {
            extract($vars, EXTR_SKIP); ob_start();
            try { require $file; return ob_get_clean(); } catch (Throwable $e) { ob_end_clean(); throw $e; }
        };
        $vars = array_merge(self::$shared,$data);
        $content = $render($template,$vars);
        if ($layout === '') return $content;
        // Fragments include the whole page main, keeping forms and sidebar navigation consistent.
        if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true' && ($_SERVER['HTTP_HX_HISTORY_RESTORE_REQUEST'] ?? '') !== 'true') return '<title>' . e($vars['title'] ?? 'LifeOS') . ' · LifeOS</title><main id="main" class="content" tabindex="-1">' . $content . '</main>';
        return $render(ROOT . '/app/Core/views/' . $layout . '.php', array_merge($vars,['content'=>$content]));
    }
}

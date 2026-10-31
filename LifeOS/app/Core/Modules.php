<?php
declare(strict_types=1);
final class Modules {
    private static array $services = [];
    private static bool $autoloadRegistered = false;
    public static function discover(string $dir): array {
        $out = [];
        foreach (glob($dir . '/*/module.json') ?: [] as $file) {
            $folder=basename(dirname($file));
            if (str_starts_with($folder,'.') || str_ends_with($folder,'.disabled')) continue;
            $m = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (empty($m['name']) || !preg_match('~^/[a-z0-9/-]*$~', $m['route'] ?? '')) throw new RuntimeException('Invalid module manifest: ' . $file);
            $m['path'] = dirname($file); $out[] = $m;
        }
        usort($out, fn($a,$b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99)); return $out;
    }
    public static function register(array $modules, Router $r): void {
        $services = [];
        foreach ($modules as $m) {
            $files = [];
            foreach (glob($m['path'] . '/src/*.php') ?: [] as $file) {
                $files[basename($file)] = $file;
                $services[strtolower(pathinfo($file,PATHINFO_FILENAME))] = $file;
            }
            // Most services use their class name as the filename. A module can
            // declare exceptions without making every request parse that class.
            $aliases = $m['autoload'] ?? [];
            if (!is_array($aliases)) throw new RuntimeException('Invalid module service map: ' . $m['path']);
            foreach ($aliases as $class => $filename) {
                if (!is_string($class) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$class) || !is_string($filename) || !isset($files[$filename])) throw new RuntimeException('Invalid module service map: ' . $m['path']);
                $services[strtolower($class)] = $files[$filename];
            }
        }
        self::$services = $services;
        if (!self::$autoloadRegistered) {
            spl_autoload_register(static function (string $class): void {
                $file = self::$services[strtolower($class)] ?? null;
                if ($file !== null) require_once $file;
            });
            self::$autoloadRegistered = true;
        }
        foreach ($modules as $m) if (is_file($m['path'] . '/routes.php')) (require $m['path'] . '/routes.php')($r);
    }
}

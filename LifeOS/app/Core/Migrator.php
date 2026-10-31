<?php
declare(strict_types=1);
final class Migrator {
    public static function run(array $dirs): void {
        Db::exec('CREATE TABLE IF NOT EXISTS migrations(file TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $appliedMap = [];
        foreach (Db::all('SELECT file, checksum FROM migrations') as $row) {
            $appliedMap[$row['file']] = $row['checksum'];
        }
        foreach ($dirs as $dir) {
            $files = glob($dir . '/*.sql') ?: []; sort($files);
            foreach ($files as $file) {
                $key = basename(dirname($dir)) . '/' . basename($file);
                $hash = hash_file('sha256', $file);
                if (isset($appliedMap[$key])) {
                    if ($appliedMap[$key] !== $hash) throw new RuntimeException('An applied migration was changed: ' . $key);
                    continue;
                }
                Db::tx(function () use ($file, $key, $hash) { Db::exec(file_get_contents($file)); Db::run('INSERT INTO migrations(file,checksum) VALUES (?,?)', [$key,$hash]); });
                $appliedMap[$key] = $hash;
            }
        }
    }
}

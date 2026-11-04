<?php
declare(strict_types=1);
final class Db {
    private static ?PDO $pdo = null;
    private static array $settings = [];
    public static function init(string $file): void {
        self::$settings = [];
        if ($file !== ':memory:' && !is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        self::$pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        self::$pdo->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA cache_size=-20000; PRAGMA temp_store=MEMORY;');
    }
    public static function all(string $sql, array $p = []): array { $s = self::$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
    public static function one(string $sql, array $p = []): ?array { return self::all($sql, $p)[0] ?? null; }
    public static function run(string $sql, array $p = []): int { self::$settings=[]; $s = self::$pdo->prepare($sql); $s->execute($p); return (int) self::$pdo->lastInsertId(); }
    public static function exec(string $sql): void { self::$settings=[]; self::$pdo->exec($sql); }
    public static function tx(callable $fn): mixed {
        self::$pdo->beginTransaction();
        try { $value = $fn(); self::$pdo->commit(); return $value; }
        catch (Throwable $e) { self::$settings=[]; if (self::$pdo->inTransaction()) self::$pdo->rollBack(); throw $e; }
    }
    public static function close(): void { self::$pdo = null; self::$settings = []; }
    public static function setting(string $key, string $fallback = ''): string {
        if (!array_key_exists($key,self::$settings)) self::$settings[$key] = self::one('SELECT value FROM settings WHERE key=?', [$key])['value'] ?? null;
        return self::$settings[$key] ?? $fallback;
    }
    public static function set(string $key, string $value): void { self::run('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key,$value]); self::$settings[$key]=$value; }
}

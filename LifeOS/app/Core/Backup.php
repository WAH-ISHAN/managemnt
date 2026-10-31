<?php
declare(strict_types=1);
final class Backup {
    public static function create(string $dir, string $label = 'manual'): string {
        if (!is_dir($dir)) mkdir($dir,0700,true);
        $file = $dir . '/lifeos-' . date('Y-m-d-His') . '-' . $label . '-' . bin2hex(random_bytes(3)) . '.db';
        Db::run('VACUUM INTO ?',[$file]); return $file;
    }
    public static function daily(string $dir): void {
        if (!glob($dir . '/lifeos-' . date('Y-m-d') . '-*-daily-*.db')) self::create($dir,'daily');
        $files = glob($dir . '/lifeos-*-daily-*.db') ?: []; sort($files);
        foreach (array_slice($files,0,max(0,count($files)-14)) as $old) unlink($old);
    }
    public static function validate(string $file, array $dirs): void {
        $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $db->query('PRAGMA foreign_key_check')->fetch()) throw new InvalidArgumentException('This database did not pass its integrity check.');
        $required = ['settings','tasks','migrations','accounts','income','allocations','allocation_rules','expenses','expense_categories'];
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($required,$tables)) throw new InvalidArgumentException('Choose a LifeOS database from this version.');
        $expected = [];
        foreach ($dirs as $dir) foreach (glob($dir . '/*.sql') ?: [] as $f) $expected[basename(dirname($dir)) . '/' . basename($f)] = hash_file('sha256',$f);
        $actual = $db->query('SELECT file,checksum FROM migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($expected != $actual) throw new InvalidArgumentException('This backup uses different modules or migrations. Restore it with the matching LifeOS version.');
        $schema="SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name";
        if ($db->query($schema)->fetchAll(PDO::FETCH_ASSOC)!==Db::all($schema)) throw new InvalidArgumentException('The backup schema does not match this LifeOS installation.');
    }
    public static function restore(string $file, string $live, string $dir, array $migrations): void {
        self::validate($file,$migrations); self::create($dir,'before-restore');
        Db::exec('PRAGMA wal_checkpoint(TRUNCATE)'); Db::close();
        try {
            $source = new SQLite3($file,SQLITE3_OPEN_READONLY); $target = new SQLite3($live); $target->busyTimeout(5000);
            if (!$source->backup($target)) throw new RuntimeException('Database restore failed. Your previous backup is in data/backups.');
            $target->close(); $source->close();
        } finally { Db::init($live); }
    }
}

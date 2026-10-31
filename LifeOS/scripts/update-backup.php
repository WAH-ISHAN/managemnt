<?php
declare(strict_types=1);
// Called only after the updater has observed the launcher and its PHP child exit.
if ($argc !== 3) { fwrite(STDERR, "Expected source database and backup path.\n"); exit(2); }
$source = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
if ($source->querySingle('PRAGMA integrity_check') !== 'ok') { throw new RuntimeException('Existing database integrity check failed.'); }
$target = new SQLite3($argv[2], SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
if (!$source->backup($target) || $target->querySingle('PRAGMA integrity_check') !== 'ok') { throw new RuntimeException('Could not create a verified update database backup.'); }
$target->close();
$source->close();

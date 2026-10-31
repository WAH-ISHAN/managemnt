<?php
declare(strict_types=1);
exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 && extension_loaded('pdo_sqlite') && extension_loaded('sqlite3') && extension_loaded('mbstring') && extension_loaded('openssl') && extension_loaded('zip') && extension_loaded('fileinfo') && extension_loaded('curl') ? 0 : 1);

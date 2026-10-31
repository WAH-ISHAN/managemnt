<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__)); date_default_timezone_set('Asia/Colombo');
foreach (['Db','Migrator','Modules','helpers'] as $file) require ROOT.'/app/Core/'.$file.'.php';
foreach (Modules::discover(ROOT.'/app/Modules') as $m) foreach (glob($m['path'].'/src/*.php') ?: [] as $file) require_once $file;

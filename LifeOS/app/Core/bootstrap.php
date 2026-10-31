<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__,2));
define('DATA',getenv('LIFEOS_DATA_DIR') ?: ROOT . '/data');
date_default_timezone_set('Asia/Colombo');
foreach (['Db','Migrator','Router','View','Modules','Auth','Backup','helpers'] as $file) require __DIR__ . '/' . $file . '.php';
try {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^127\.0\.0\.1(?::\d+)?$/',$host)) { http_response_code(403); exit('LifeOS is available on 127.0.0.1 only.'); }
    foreach (['','/sessions','/backups','/uploads'] as $dir) if (!is_dir(DATA . $dir)) mkdir(DATA . $dir,0700,true);
    $encryptedService=ROOT.'/app/Modules/Settings/src/EncryptedBackupService.php';
    if (is_file(DATA.'/restore-journal.json') && is_file($encryptedService)) { require_once $encryptedService; EncryptedBackupService::recoverPending(DATA); }
    ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log',DATA . '/php_error.log');
    session_save_path(DATA . '/sessions'); session_name('lifeos');
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','path'=>'/']); session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    Db::init(DATA . '/lifeos.db'); $mods = Modules::discover(ROOT . '/app/Modules');
    $dirs = array_merge([__DIR__ . '/migrations'],array_map(fn($m)=>$m['path'] . '/migrations',$mods));
    Migrator::run($dirs); View::share('menu',$mods); View::share('title','LifeOS'); View::share('migrationDirs',$dirs);
    $path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';
    if ($path === '/health') { header('Content-Type: text/plain'); echo 'LifeOS:' . (getenv('LIFEOS_INSTANCE') ?: 'development'); exit; }
    try { Backup::daily(DATA . '/backups'); } catch (Throwable $e) { error_log('Backup: ' . $e); View::share('backupWarning','Daily backup failed. Check free disk space and folder permissions.'); }
    $router = new Router(); Auth::guard($router); Modules::register($mods,$router);
    $router->before(function (string $method,string $path) {
        if ($method!=='GET' || in_array($path,['/unlock','/salary/recurring'],true) || str_contains($path,'restore') || !class_exists('FinancePlanningService') || time()-(int)Db::setting('recurrence_last_scan','0')<60) return;
        Db::set('recurrence_last_scan',(string)time());
        $result=FinancePlanningService::processDue(null,25);
        if (!empty($result['errors'])) View::share('backupWarning','A recurring entry needs your attention. Open Money â†’ Recurring to review it.');
    });
    $router->dispatch($_SERVER['REQUEST_METHOD'],$path);
} catch (InvalidArgumentException $e) {
    http_response_code(422); header('HX-Retarget: #main'); header('HX-Reswap: outerHTML');
    $back=match(true) { str_starts_with($path ?? '','/work')=>'/work', str_starts_with($path ?? '','/web-admin')=>'/web-admin', str_starts_with($path ?? '','/websites')=>'/websites', str_starts_with($path ?? '','/life-admin')=>'/life-admin',str_starts_with($path ?? '','/small-business')=>'/small-business', str_starts_with($path ?? '','/unlock')=>'/unlock',str_starts_with($path ?? '','/welcome')=>'/welcome',str_starts_with($path ?? '','/freedom')=>'/freedom',str_starts_with($path ?? '','/planner')=>'/planner',str_starts_with($path ?? '','/growth')=>'/growth',str_starts_with($path ?? '','/career')=>'/career',str_starts_with($path ?? '','/focus')=>'/focus',str_starts_with($path ?? '','/insights')=>'/insights',str_starts_with($path ?? '','/documents')=>'/documents',str_starts_with($path ?? '','/investments'),str_starts_with($path ?? '','/goals')=>'/investments',str_starts_with($path ?? '','/uni')=>'/uni',str_starts_with($path ?? '','/review')=>'/review',str_starts_with($path ?? '','/lessons'),str_starts_with($path ?? '','/tasks')=>'/lessons',str_starts_with($path ?? '','/salary/plan')=>'/salary/plan',str_starts_with($path ?? '','/salary/calendar'),str_starts_with($path ?? '','/salary/obligations')=>'/salary/calendar',str_starts_with($path ?? '','/salary/recurring')=>'/salary/recurring',str_starts_with($path ?? '','/salary'),str_starts_with($path ?? '','/expenses')=>'/salary',default=>'/settings' };
    echo View::render(__DIR__ . '/views/error.php',['title'=>'Check your entry','message'=>$e->getMessage(),'back'=>$back]);
} catch (Throwable $e) {
    error_log((string)$e); http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>LifeOS</title><h1>LifeOS could not complete this request.</h1><p>Your database has not been reset. Check data/php_error.log, then restart the app.</p>';
}

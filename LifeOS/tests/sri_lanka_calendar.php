<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function holiday_check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$file=sys_get_temp_dir().'/lifeos-holidays-'.bin2hex(random_bytes(6)).'.db';
try {
    Db::init($file);
    $modules=Modules::discover(ROOT.'/app/Modules');
    Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    SriLankaCalendarService::seed2026();
    $calendar=SriLankaCalendarService::listing(2026);
    holiday_check($calendar['dataset']['revision']==='2026-gazette-2485-14-2','Dataset has an explicit amendment revision');
    holiday_check(count($calendar['holidays'])===27,'Original named entries and later amendment are present');
    $may1=array_values(array_filter($calendar['holidays'],fn($row)=>$row['date']==='2026-05-01'));
    holiday_check(count($may1)===2 && array_sum(array_column($may1,'is_poya'))===1,'Both May 1 holiday reasons remain separate');
    holiday_check((int)Db::one("SELECT is_public FROM holidays WHERE date='2026-05-02'")['is_public']===0,'Superseded May 2 public flag is removed');
    holiday_check((int)Db::one("SELECT is_public FROM holidays WHERE date='2026-05-31'")['is_public']===1,'Amended May 31 public holiday is included');
    holiday_check((int)Db::one("SELECT COUNT(*) n FROM holidays WHERE is_poya=1")['n']===13,'Thirteen named full moon Poya days are marked');
    holiday_check((int)Db::one("SELECT is_mercantile FROM holidays WHERE title_en='Tamil Thai Pongal Day'")['is_mercantile']===1,'Mercantile type is retained');
    SriLankaCalendarService::seed2026();
    holiday_check((int)Db::one('SELECT COUNT(*) n FROM holidays')['n']===27,'Repeated seed does not duplicate entries');
    Db::run("UPDATE holiday_datasets SET revision='2026-desk-calendar-1' WHERE country='LK' AND year=2026");
    Db::run("UPDATE holidays SET is_public=1 WHERE date='2026-05-02'");
    Db::run("DELETE FROM holidays WHERE date='2026-05-31'");
    SriLankaCalendarService::seed2026();
    holiday_check((int)Db::one("SELECT is_public FROM holidays WHERE date='2026-05-02'")['is_public']===0 && (int)Db::one("SELECT COUNT(*) n FROM holidays WHERE date='2026-05-31'")['n']===1,'A prior dataset revision is corrected atomically');
    holiday_check((int)Db::one('SELECT COUNT(*) n FROM holidays')['n']===27,'Revision replacement keeps one copy of each holiday');
    holiday_check(SriLankaCalendarService::listing(2027)['holidays']===[],'Unknown year has no estimated holidays');
    holiday_check(SriLankaCalendarService::listing(2026)['school_terms']===[],'School terms are not inferred from public holidays');
    try { SriLankaCalendarService::year('2026 OR 1=1'); throw new RuntimeException('Unsafe year accepted'); } catch (InvalidArgumentException) { $checks++; }
    Db::close();
    echo "$checks Sri Lanka calendar checks passed.\n";
} finally {
    Db::close();
    foreach ([$file,$file.'-wal',$file.'-shm'] as $path) if (is_file($path)) unlink($path);
}

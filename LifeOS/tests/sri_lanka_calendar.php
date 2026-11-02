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
    holiday_check($calendar['dataset']['revision']==='2026-desk-calendar-1','Dataset has an explicit revision');
    holiday_check(count($calendar['holidays'])===26,'All 26 named government calendar entries are present');
    $may1=array_values(array_filter($calendar['holidays'],fn($row)=>$row['date']==='2026-05-01'));
    holiday_check(count($may1)===2 && array_sum(array_column($may1,'is_poya'))===1,'Both May 1 holiday reasons remain separate');
    holiday_check((int)Db::one("SELECT COUNT(*) n FROM holidays WHERE is_poya=1")['n']===13,'Thirteen named full moon Poya days are marked');
    holiday_check((int)Db::one("SELECT is_mercantile FROM holidays WHERE title_en='Tamil Thai Pongal Day'")['is_mercantile']===1,'Mercantile type is retained');
    SriLankaCalendarService::seed2026();
    holiday_check((int)Db::one('SELECT COUNT(*) n FROM holidays')['n']===26,'Repeated seed does not duplicate entries');
    holiday_check(SriLankaCalendarService::listing(2027)['holidays']===[],'Unknown year has no estimated holidays');
    holiday_check(SriLankaCalendarService::listing(2026)['school_terms']===[],'School terms are not inferred from public holidays');
    try { SriLankaCalendarService::year('2026 OR 1=1'); throw new RuntimeException('Unsafe year accepted'); } catch (InvalidArgumentException) { $checks++; }
    Db::close();
    echo "$checks Sri Lanka calendar checks passed.\n";
} finally {
    Db::close();
    foreach ([$file,$file.'-wal',$file.'-shm'] as $path) if (is_file($path)) unlink($path);
}

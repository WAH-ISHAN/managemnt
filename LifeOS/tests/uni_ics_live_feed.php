<?php
declare(strict_types=1);
// Optional external smoke check; runs in a disposable profile and never uses a personal calendar.
$temporary=sys_get_temp_dir().'/lifeos-public-ics-'.bin2hex(random_bytes(6));
mkdir($temporary,0700,true); define('DATA',$temporary);
require __DIR__.'/bootstrap.php';
try {
    Db::init($temporary.'/lifeos.db');
    $modules=Modules::discover(ROOT.'/app/Modules');
    Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    $result=UniIcsSubscription::connect('https://www.gov.uk/bank-holidays/england-and-wales.ics');
    if (!$result['added'] || !UniIcsSubscription::status()['connected']) throw new RuntimeException('Public feed did not connect.');
    $count=(int)Db::one('SELECT COUNT(*) n FROM uni_ics_events')['n'];
    Db::close(); Db::init($temporary.'/lifeos.db');
    if ((int)Db::one('SELECT COUNT(*) n FROM uni_ics_events')['n']!==$count || !UniIcsSubscription::status()['connected']) throw new RuntimeException('Connection did not persist after reopen.');
    $second=UniIcsSubscription::sync();
    if (($second['added'] ?? 0)!==0 || ($second['changed'] ?? 0)!==0) throw new RuntimeException('Unchanged public feed created duplicates.');
    echo "Public HTTPS ICS connection, {$count} cached events, reopen and repeat sync passed.\n";
} finally {
    Db::close();
    foreach (array_merge(glob($temporary.'/*') ?: [],glob($temporary.'/.connectivity-key') ?: []) as $file) if (is_file($file)) unlink($file);
    if (is_dir($temporary)) rmdir($temporary);
}

<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$data=$argv[1] ?? ''; if (!is_dir($data)) throw new RuntimeException('Provide an existing disposable data directory.');
Db::init($data.'/lifeos.db');
$mods=Modules::discover(ROOT.'/app/Modules');
Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$mods)));
if (Db::one('SELECT id FROM income LIMIT 1')) throw new RuntimeException('Load test seeding requires an empty database.');
for ($i=0;$i<120;$i++) SalaryService::addIncome(date('Y-m-d',strtotime('-'.$i.' day')),'Synthetic income '.$i,'10000');
Db::tx(function () {
    for ($i=0;$i<180;$i++) Db::run('INSERT INTO expenses(date,category_id,account_id,amount,note) VALUES (?,?,?,?,?)',[date('Y-m-d',strtotime('-'.$i.' day')),1,1,500,'Synthetic expense']);
    for ($i=0;$i<24;$i++) {
        $asset=Db::run('INSERT INTO assets(name,type,start_date) VALUES (?,?,?)',['Synthetic asset '.$i,'business','2025-01-01']);
        for ($j=0;$j<120;$j++) Db::run('INSERT INTO asset_transactions(asset_id,date,type,amount,note) VALUES (?,?,?,?,?)',[$asset,date('Y-m-d',strtotime('-'.(120-$j).' day')),$j%10===0 ? 'valuation' : 'deposit',10000+$j*100,'Synthetic entry']);
        for ($j=0;$j<12;$j++) Db::run('INSERT INTO asset_metrics(asset_id,month,revenue,cost) VALUES (?,?,?,?)',[$asset,date('Y-m',strtotime('first day of -'.$j.' month')),20000,10000]);
        Db::run('INSERT INTO goals(title,target_amount,asset_id) VALUES (?,?,?)',['Synthetic goal '.$i,1000000,$asset]);
    }
    for ($i=0;$i<12;$i++) {
        $m=Db::run('INSERT INTO uni_modules(name,credits,grade,semester) VALUES (?,?,?,?)',['Synthetic module '.$i,3,'A','Test semester']);
        for ($j=0;$j<10;$j++) Db::run('INSERT INTO assignments(module_id,title,due_date) VALUES (?,?,?)',[$m,'Synthetic assignment '.$j,date('Y-m-d',strtotime('+'.$j.' day'))]);
        Db::run('INSERT INTO exams(module_id,date,venue) VALUES (?,?,?)',[$m,date('Y-m-d',strtotime('+7 days')),'Synthetic venue']);
    }
    for ($i=0;$i<120;$i++) Db::run('INSERT INTO tasks(title,due,source) VALUES (?,?,?)',['Synthetic task '.$i,date('Y-m-d'),'manual']);
    for ($i=0;$i<60;$i++) Db::run('INSERT INTO lessons(area,what_happened,root_cause,lesson) VALUES (?,?,?,?)',['Uni','Synthetic missed deadline '.$i,'Synthetic cause','Synthetic lesson']);
    for ($i=0;$i<26;$i++) Db::run('INSERT INTO weekly_reviews(week,wins,fails,score,focus) VALUES (?,?,?,?,?)',[LessonService::weekId(strtotime('-'.$i.' weeks')),'Synthetic win','Synthetic lesson',7,'Synthetic focus']);
});
$counts=[]; foreach (SettingsService::tables() as $table) $counts[$table]=(int)Db::one('SELECT COUNT(*) n FROM '.$table)['n'];
echo json_encode($counts,JSON_PRETTY_PRINT); Db::close();

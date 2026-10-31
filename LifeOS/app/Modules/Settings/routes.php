<?php
return function (Router $r) {
    if (is_file(__DIR__.'/encrypted-routes.php')) (require __DIR__.'/encrypted-routes.php')($r);
    $r->get('/settings',fn()=>View::render(__DIR__.'/views/index.php',['title'=>'Settings','assets'=>class_exists('InvestmentService') ? Db::all("SELECT * FROM assets WHERE status='active' ORDER BY name") : [],'accounts'=>Db::all('SELECT * FROM accounts ORDER BY id'),'rules'=>Db::all('SELECT * FROM allocation_rules ORDER BY sort,id'),'categories'=>Db::all('SELECT * FROM expense_categories ORDER BY name'),'pinSet'=>Db::setting('pin_hash')!=='','backups'=>array_reverse(glob(DATA.'/backups/lifeos-*.db') ?: []),'exportTables'=>SettingsService::tables()]));
    $r->post('/settings/name',function () { Db::set('name',text_input('name',100)); return redirect('/settings','Profile saved.'); });
    $r->post('/settings/grades',function () { if (!class_exists('UniService')) throw new InvalidArgumentException('University module is unavailable.'); UniService::savePoints(text_input('grade_points',5000)); return redirect('/settings','Grade points saved.'); });
    $r->get('/settings/export/{table}',fn($p)=>SettingsService::csv($p['table']));
    $r->get('/settings/backup/{file}',function ($p) {
        $file=$p['file']; if (!preg_match('/^lifeos-[a-z0-9-]+\.db$/',$file) || !is_file(DATA.'/backups/'.$file)) { http_response_code(404); return 'Backup not found'; }
        header('Content-Type: application/vnd.sqlite3'); header('Content-Disposition: attachment; filename="'.$file.'"'); header('Content-Length: '.filesize(DATA.'/backups/'.$file)); readfile(DATA.'/backups/'.$file); return '';
    });
    $r->get('/settings/report',fn()=>View::render(__DIR__.'/views/report.php',['title'=>'LifeOS report','tables'=>SettingsService::tables()]));
    $r->post('/settings/rules',function () { $rows = $_POST['rules'] ?? []; if (!is_array($rows)) throw new InvalidArgumentException('Invalid rules.'); SalaryService::saveRules($rows); return redirect('/settings','Allocation rules saved. They apply to new income.'); });
    $r->post('/settings/accounts',function () {
        $kind = text_input('kind'); if (!in_array($kind,['cash','spending','savings','business'],true)) throw new InvalidArgumentException('Choose an account type.');
        $name = text_input('name',100); if (Db::one('SELECT id FROM accounts WHERE name=?',[$name])) throw new InvalidArgumentException('That account name is already in use.');
        Db::run('INSERT INTO accounts(name,kind) VALUES (?,?)',[$name,$kind]); return redirect('/settings','Account created.');
    });
    $r->post('/settings/categories',function () {
        $name = text_input('name',100); $budget = cents($_POST['budget'] ?? '',true)/100;
        if (Db::one('SELECT id FROM expense_categories WHERE name=?',[$name])) throw new InvalidArgumentException('That category already exists.');
        Db::run('INSERT INTO expense_categories(name,monthly_budget) VALUES (?,?)',[$name,$budget]); return redirect('/settings','Category created.');
    });
    $r->post('/settings/budgets',function () {
        $rows=$_POST['budgets'] ?? []; if (!is_array($rows)) throw new InvalidArgumentException('Invalid budgets.');
        Db::tx(function () use ($rows) { foreach ($rows as $id=>$value) Db::run('UPDATE expense_categories SET monthly_budget=? WHERE id=?',[cents($value,true)/100,record_id($id)]); });
        return redirect('/settings','Monthly budgets saved.');
    });
    $r->post('/settings/pin',function () { Auth::changePin(); return redirect('/settings','PIN settings updated.'); });
    $r->post('/settings/backup',function () { Backup::create(DATA.'/backups'); return redirect('/settings','A local backup was created.'); });
    $r->post('/settings/export',function () {
        $file=Backup::create(DATA.'/backups','export'); header('Content-Type: application/vnd.sqlite3'); header('Content-Disposition: attachment; filename="lifeos-'.date('Y-m-d').'.db"'); header('Content-Length: '.filesize($file)); readfile($file); return '';
    });
    $r->post('/settings/restore',function () {
        if (($_POST['confirm'] ?? '') !== 'RESTORE') throw new InvalidArgumentException('Type RESTORE to confirm replacing current data.');
        $f=$_FILES['database'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size']>256*1024*1024 || !is_uploaded_file($f['tmp_name'])) throw new InvalidArgumentException('Choose a LifeOS .db backup up to 256 MB.');
        $mods=Modules::discover(ROOT.'/app/Modules'); $dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$mods));
        try { Backup::restore($f['tmp_name'],DATA.'/lifeos.db',DATA.'/backups',$dirs); }
        catch (PDOException $e) { throw new InvalidArgumentException('The selected file is not a compatible LifeOS database.'); }
        $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); return redirect('/','Backup restored.');
    });
};

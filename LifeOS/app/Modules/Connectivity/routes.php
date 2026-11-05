<?php
declare(strict_types=1);
return function (Router $r): void {
    $r->get('/connectivity',fn()=>View::render(__DIR__.'/views/index.php',['title'=>t('Connections & automation'),'config'=>ConnectivityService::config(),'tokens'=>Db::all('SELECT id,name,scopes,expires_at FROM connectivity_tokens ORDER BY id DESC'),'runs'=>Db::all('SELECT * FROM connectivity_runs ORDER BY id DESC LIMIT 25'),'mail'=>Db::all("SELECT * FROM connectivity_mail WHERE state='preview' ORDER BY id DESC LIMIT 50"),'macros'=>Db::all('SELECT * FROM connectivity_macros ORDER BY id DESC'),'macroRuns'=>Db::all('SELECT * FROM connectivity_script_runs ORDER BY id DESC LIMIT 20'),'tracking'=>Db::all('SELECT category,process,SUM(seconds) seconds FROM connectivity_window_time GROUP BY category,process ORDER BY seconds DESC LIMIT 30')]));
    $r->post('/connectivity/config',function (): string { ConnectivityService::saveConfig($_POST); return redirect('/connectivity','Connection preferences saved. Blank secret fields keep existing credentials.'); });
    $r->post('/connectivity/backup',function (): string { ConnectivityService::backup(); return redirect('/connectivity','Encrypted external backup verified.'); });
    $r->post('/connectivity/mail/poll',function (): string { $n=ConnectivityService::pollMail(); return redirect('/connectivity',$n.' new email preview(s).'); });
    $r->post('/connectivity/mail/{id}/accept',function (array $p): string { ConnectivityService::acceptMail(record_id($p['id'])); return redirect('/connectivity','Email task created once.'); });
    $r->post('/connectivity/mail/{id}/ignore',function (array $p): string { Db::run("UPDATE connectivity_mail SET state='ignored' WHERE id=? AND state='preview'",[record_id($p['id'])]); return redirect('/connectivity','Email ignored.'); });
    $r->post('/connectivity/calendar/sync',function (): string { ConnectivityCalendar::sync(); return redirect('/connectivity','Calendar sync finished. Review the run history for conflicts.'); });
    $r->get('/connectivity/calendar/export',function (): string { if (!module_enabled('Planner')) throw new InvalidArgumentException(t('Planner is switched off.')); header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="lifeos-calendar.ics"'); return ConnectivityIcs::export(); });
    $r->post('/connectivity/calendar/preview',function (): string { if (!module_enabled('Planner')) throw new InvalidArgumentException(t('Planner is switched off.')); $ics=$_POST['ics'] ?? ''; if (!is_string($ics)) throw new InvalidArgumentException(t('Paste ICS text.')); $from=valid_date($_POST['from'] ?? date('Y-m-d')); $until=valid_date($_POST['until'] ?? (new DateTimeImmutable($from))->modify('+1 year')->format('Y-m-d')); $events=ConnectivityIcs::preview($ics,$from,$until); $_SESSION['connection_ics_range']=[$from,$until]; $_SESSION['connection_ics_review']=ConnectivityIcs::reviewHash($ics,$from,$until); $_SESSION['connection_ics']=$ics; return View::render(__DIR__.'/views/ics.php',['title'=>t('Review calendar import'),'events'=>$events]); });
    $r->post('/connectivity/calendar/import',function (): string { if (!module_enabled('Planner')) throw new InvalidArgumentException(t('Planner is switched off.')); $ics=$_SESSION['connection_ics'] ?? ''; $range=$_SESSION['connection_ics_range'] ?? [null,null]; $review=$_SESSION['connection_ics_review'] ?? throw new InvalidArgumentException(t('Preview a calendar first.')); $n=ConnectivityIcs::import($ics,$range[0],$range[1],$review); unset($_SESSION['connection_ics_review']); unset($_SESSION['connection_ics_range']); unset($_SESSION['connection_ics']); return redirect('/connectivity',$n.' calendar event(s) imported.'); });
    $r->post('/connectivity/tokens',function (): string { $scopes=$_POST['scopes'] ?? []; if (!is_array($scopes)) throw new InvalidArgumentException(t('Choose API permissions.')); $token=ConnectivityService::token(text_input('name',100),$scopes); return View::render(__DIR__.'/views/token.php',['title'=>t('Save your API token'),'token'=>$token]); });
    $r->post('/connectivity/tokens/{id}/revoke',function (array $p): string { Db::run('DELETE FROM connectivity_tokens WHERE id=?',[record_id($p['id'])]); return redirect('/connectivity','API token revoked.'); });
    $r->post('/connectivity/macros',function (): string { ConnectivityService::saveMacro($_POST); return redirect('/connectivity','Macro saved.'); });
    $r->post('/connectivity/macros/{id}/run',function (array $p): string { ConnectivityService::runMacro(record_id($p['id'])); return redirect('/connectivity','Macro finished.'); });
    $r->post('/connectivity/macros/{id}/toggle',function (array $p): string { Db::run('UPDATE connectivity_macros SET enabled=1-enabled WHERE id=?',[record_id($p['id'])]); return redirect('/connectivity','Macro schedule preference updated.'); });
    $r->post('/connectivity/macros/{id}/delete',function (array $p): string { Db::run('DELETE FROM connectivity_macros WHERE id=?',[record_id($p['id'])]); return redirect('/connectivity','Macro removed.'); });
    $r->post('/connectivity/tracking/clear',function (): string { Db::run('DELETE FROM connectivity_window_time'); return redirect('/connectivity','Captured application time deleted.'); });
    $r->post('/connectivity/secrets/forget',function (): string { foreach (['backup_passphrase','mail_password','calendar_access_token','calendar_refresh_token','calendar_client_secret'] as $name) ConnectivitySecrets::forget($name); return redirect('/connectivity','Saved connection credentials removed. Scheduled connections need reconfiguration.'); });
    $api=function (string $method,string $path): string {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $raw=file_get_contents('php://input',false,null,0,65537); if (strlen($raw)>65536) throw new InvalidArgumentException(t('API payload exceeds 64 KiB.'));
            $data=$raw!=='' ? json_decode($raw,true,16,JSON_THROW_ON_ERROR) : [];
            if (!is_array($data)) throw new InvalidArgumentException(t('Send a JSON object.'));
            return json_encode(ConnectivityService::api($method,$path,$data),JSON_THROW_ON_ERROR);
        } catch (InvalidArgumentException|JsonException $e) { http_response_code(422); return json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR); }
    };
    $r->get('/api/v1/tasks',fn()=>$api('GET','/api/v1/tasks'));
    $r->post('/api/v1/tasks',fn()=>$api('POST','/api/v1/tasks'));
    $r->post('/api/v1/tasks/{id}/complete',fn(array $p)=>$api('POST','/api/v1/tasks/'.$p['id'].'/complete'));
    $r->post('/api/v1/window-time',fn()=>$api('POST','/api/v1/window-time'));
};

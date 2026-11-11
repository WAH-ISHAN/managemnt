<?php
declare(strict_types=1);
return function (Router $r): void {
    $r->post('/automation/weather',function (): string { WeatherService::configure(isset($_POST['enabled']),text_input('latitude',30),text_input('longitude',30)); return redirect('/automation','Weather preference saved.'); });
    $r->get('/automation',function (): string {
        $editing=isset($_GET['edit']) ? AutomationService::rule(record_id($_GET['edit'])) : null;
        $search=isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        if (mb_strlen($search)>100) throw new InvalidArgumentException(t('Search is too long.'));
        $rules=Db::all('SELECT a.*,(SELECT MAX(r.created_at) FROM automation_runs r WHERE r.rule_id=a.id) last_run FROM automation_rules a WHERE a.profile_id=?'.($search!=='' ? ' AND a.name LIKE ?' : '').' ORDER BY a.id DESC',$search!=='' ? [AutomationService::profileId(),'%'.$search.'%'] : [AutomationService::profileId()]);
        $counts=Db::one("SELECT COUNT(*) total, COALESCE(SUM(enabled),0) enabled FROM automation_rules WHERE profile_id=?",[AutomationService::profileId()]);
        $runCounts=Db::all('SELECT r.status,COUNT(*) count FROM automation_runs r JOIN automation_rules a ON a.id=r.rule_id WHERE a.profile_id=? GROUP BY r.status',[AutomationService::profileId()]);
        return View::render(__DIR__.'/views/index.php',[
            'title'=>t('Automations'),'editing'=>$editing,
            'rules'=>$rules,'search'=>$search,'counts'=>$counts,'runCounts'=>array_column($runCounts,'count','status'),
            'runs'=>Db::all('SELECT r.*,a.name FROM automation_runs r JOIN automation_rules a ON a.id=r.rule_id WHERE a.profile_id=? ORDER BY r.id DESC LIMIT 30',[AutomationService::profileId()]),
            'reports'=>Db::all('SELECT * FROM automation_reports WHERE profile_id=? ORDER BY id DESC LIMIT 30',[AutomationService::profileId()]),
        ]);
    });
    $r->post('/automation/rules',function (): string {
        $days=$_POST['weekdays'] ?? []; if (!is_array($days)) throw new InvalidArgumentException(t('Choose valid weekdays.'));
        if (isset($_POST['actions'])) {
            $posted=$_POST['actions'];
            if (!is_array($posted) || !array_is_list($posted) || count($posted)>5) throw new InvalidArgumentException(t('Choose between one and five actions.'));
            $actions=[];
            foreach ($posted as $action) {
                if (!is_array($action)) throw new InvalidArgumentException(t('Choose valid actions.'));
                if (($action['type'] ?? null)==='') continue;
                $actions[]=$action;
            }
        } else {
            // Keep forms opened before this update compatible.
            $action=['type'=>text_input('action',40)];
            if ($action['type']==='notify') $action+=['title'=>text_input('title',150),'body'=>text_input('body',1000)];
            $actions=[$action];
        }
        AutomationService::save(optional_id($_POST['id'] ?? ''),text_input('name',150),text_input('trigger',20),['time'=>text_input('time',5),'weekdays'=>$days,'min_open_tasks'=>$_POST['min_open_tasks'] ?? 0,'timezone'=>$_POST['timezone'] ?? 'Asia/Colombo','event'=>$_POST['event'] ?? '','catch_up_days'=>$_POST['catch_up_days'] ?? 0,'retry_limit'=>$_POST['retry_limit'] ?? 0,'retry_minutes'=>$_POST['retry_minutes'] ?? 5],$actions,isset($_POST['enabled']));
        return redirect('/automation','Automation saved.');
    });
    $r->post('/automation/templates',function (): string { AutomationService::install(text_input('template',30)); return redirect('/automation','Automation enabled. Existing schedule and action edits are kept; repeated clicks add no duplicate routine.'); });
    $r->post('/automation/rules/{id}/toggle',function (array $p): string { $enabled=text_input('enabled',1); if (!in_array($enabled,['0','1'],true)) throw new InvalidArgumentException(t('Choose enabled or paused.')); AutomationService::toggle(record_id($p['id']),$enabled==='1'); return redirect('/automation','Automation preference saved.'); });
    $r->post('/automation/rules/{id}/run',function (array $p): string { $result=AutomationService::runNow(record_id($p['id'])); return redirect('/automation',$result['ran'].' routine run(s) completed; '.$result['failed'].' failed.'); });
    $r->post('/automation/runs/{id}/retry',function (array $p): string { $result=AutomationService::retryRun(record_id($p['id'])); return redirect('/automation',$result['ran'].' failed run(s) recovered; '.$result['failed'].' still failed.'); });
    $r->post('/automation/rules/{id}/delete',function (array $p): string { AutomationService::remove(record_id($p['id'])); return redirect('/automation','Automation and its run history removed.'); });
    $r->post('/automation/tick',function (): string { $result=AutomationService::tick(); return redirect('/automation',$result['ran'].' due rule(s) completed; '.$result['failed'].' failed.'); });
    $r->get('/automation/reports/{id}',function (array $p): string {
        $report=AutomationService::reportById(record_id($p['id']));
        return View::render(__DIR__.'/views/report.php',['title'=>$report['kind']==='weekly' ? 'Weekly review report' : 'Monthly financial summary','report'=>$report]);
    });
};

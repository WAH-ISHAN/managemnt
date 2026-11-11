<?php
declare(strict_types=1);

/** Local, allowlisted actions only. All effects and their slot claim commit together. */
final class AutomationService {
    public const ACTIONS=['morning_brief'=>'Morning brief','evening_review'=>'Evening shutdown reminder','deadline_reminder'=>'Deadline ladder (7 / 3 / 1 / today)','weekly_report'=>'Previous week report','monthly_summary'=>'Previous month money summary','notify'=>'Custom reminder'];
    public static function profileId(): string { return class_exists('PersonaService') && method_exists('PersonaService','profileId') ? PersonaService::profileId() : 'default'; }
    private static function available(string $module): bool { return !class_exists('PersonaService') || !method_exists('PersonaService','enabled') || PersonaService::enabled($module); }
    private static function table(string $table): bool { return (bool)Db::one("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",[$table]); }
    private static function local(?DateTimeImmutable $now=null): DateTimeImmutable { return ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Asia/Colombo')); }
    private static function text(mixed $value,int $max,bool $required=true): string {
        if (!is_string($value) || str_contains($value,"\0") || mb_strlen(trim($value))>$max || ($required && trim($value)==='')) throw new InvalidArgumentException(t('Enter valid automation text (up to ').$max.' characters).');
        return trim($value);
    }
    public static function validate(string $trigger,array $conditions,array $actions): array {
        if (!in_array($trigger,['daily','weekly','monthly','deadline'],true)) throw new InvalidArgumentException(t('Choose a supported trigger.'));
        if (array_diff(array_keys($conditions),['time','weekdays','min_open_tasks','timezone','event','event_after','catch_up_days','retry_limit','retry_minutes'])) throw new InvalidArgumentException(t('Unsupported automation condition.'));
        $zone=$conditions['timezone'] ?? 'Asia/Colombo';
        if (!is_string($zone) || !in_array($zone,DateTimeZone::listIdentifiers(),true)) throw new InvalidArgumentException(t('Choose a valid timezone.'));
        $event=$conditions['event'] ?? '';
        if (!in_array($event,['','task_completed','focus_completed','habit_checked'],true) || ($event!=='' && $trigger!=='daily')) throw new InvalidArgumentException(t('Choose a supported activity event.'));
        if ($event!=='' && array_filter($actions,fn($a)=>($a['type'] ?? '')!=='notify')) throw new InvalidArgumentException(t('Activity routines support reminder actions.'));
        $extra=['timezone'=>$zone,'event'=>$event,'event_after'=>int_range($conditions['event_after'] ?? 0,0,PHP_INT_MAX),'catch_up_days'=>int_range($conditions['catch_up_days'] ?? 0,0,30),'retry_limit'=>int_range($conditions['retry_limit'] ?? 0,0,3),'retry_minutes'=>int_range($conditions['retry_minutes'] ?? 5,1,1440)];
        $time=$conditions['time'] ?? '07:00';
        if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time)) throw new InvalidArgumentException(t('Choose a valid local time.'));
        $days=$conditions['weekdays'] ?? ($trigger==='weekly' ? [1] : [1,2,3,4,5,6,7]);
        if (!is_array($days) || !$days || count($days)>7) throw new InvalidArgumentException(t('Choose at least one weekday.'));
        $days=array_values(array_unique(array_map(fn($day)=>int_range($day,1,7),$days))); sort($days);
        $minimum=int_range($conditions['min_open_tasks'] ?? 0,0,10000);
        if (!$actions || count($actions)>5 || !array_is_list($actions)) throw new InvalidArgumentException(t('Choose between one and five actions.'));
        $clean=[];
        foreach ($actions as $action) {
            if (!is_array($action) || array_diff(array_keys($action),['type','title','body'])) throw new InvalidArgumentException(t('Unsupported automation action.'));
            $type=$action['type'] ?? '';
            if (!is_string($type) || !isset(self::ACTIONS[$type])) throw new InvalidArgumentException(t('Choose a supported action.'));
            if (($trigger==='deadline')!==($type==='deadline_reminder')) throw new InvalidArgumentException(t('Use the deadline trigger with the deadline ladder action.'));
            if ($type==='weekly_report' && $trigger!=='weekly') throw new InvalidArgumentException(t('Weekly reports need a weekly trigger.'));
            if ($type==='monthly_summary' && $trigger!=='monthly') throw new InvalidArgumentException(t('Monthly summaries need a monthly trigger.'));
            if (in_array($type,array_column($clean,'type'),true)) throw new InvalidArgumentException(t('Choose each action only once.'));
            $clean[]=$type==='notify' ? ['type'=>$type,'title'=>self::text($action['title'] ?? '',150),'body'=>self::text($action['body'] ?? '',1000)] : ['type'=>$type];
        }
        return [['time'=>$time,'weekdays'=>$days,'min_open_tasks'=>$minimum]+$extra,$clean];
    }
    public static function save(?int $id,string $name,string $trigger,array $conditions,array $actions,bool $enabled=false): int {
        $name=self::text($name,150); [$conditions,$actions]=self::validate($trigger,$conditions,$actions);
        if ($conditions['event']!=='') {
            $previous=$id!==null ? json_decode(self::rule($id)['conditions'],true) : [];
            $conditions['event_after']=($previous['event'] ?? '')===$conditions['event'] ? (int)($previous['event_after'] ?? 0) : (int)(Db::one('SELECT COALESCE(MAX(id),0) n FROM automation_events')['n']);
        }
        $values=[$name,$enabled ? 1 : 0,$trigger,json_encode($conditions,JSON_THROW_ON_ERROR),json_encode($actions,JSON_THROW_ON_ERROR)];
        if ($id!==null) {
            self::rule($id);
            Db::run('UPDATE automation_rules SET name=?,enabled=?,trigger=?,conditions=?,actions=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND profile_id=?',[...$values,$id,self::profileId()]);
            return $id;
        }
        return Db::run('INSERT INTO automation_rules(name,enabled,trigger,conditions,actions,profile_id) VALUES (?,?,?,?,?,?)',[...$values,self::profileId()]);
    }
    public static function rule(int $id): array {
        $row=Db::one('SELECT * FROM automation_rules WHERE id=? AND profile_id=?',[$id,self::profileId()]);
        if (!$row) throw new InvalidArgumentException(t('Automation rule not found.')); return $row;
    }
    public static function toggle(int $id,bool $enabled): void { $rule=self::rule($id); $config=json_decode($rule['conditions'],true); if ($enabled && !$rule['enabled'] && !empty($config['event'])) { $config['event_after']=(int)Db::one('SELECT COALESCE(MAX(id),0) n FROM automation_events')['n']; Db::run('UPDATE automation_rules SET conditions=? WHERE id=?',[json_encode($config,JSON_THROW_ON_ERROR),$id]); } Db::run('UPDATE automation_rules SET enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$enabled ? 1 : 0,$id]); }
    public static function remove(int $id): void { self::rule($id); Db::run('DELETE FROM automation_rules WHERE id=?',[$id]); }
    public static function templates(): array {
        return [
            'morning'=>['name'=>'Morning brief','trigger'=>'daily','conditions'=>['time'=>'07:00'],'actions'=>[['type'=>'morning_brief']]],
            'evening'=>['name'=>'Evening shutdown','trigger'=>'daily','conditions'=>['time'=>'20:30'],'actions'=>[['type'=>'evening_review']]],
            'deadlines'=>['name'=>'Deadline ladder','trigger'=>'deadline','conditions'=>['time'=>'07:00'],'actions'=>[['type'=>'deadline_reminder']]],
            'weekly'=>['name'=>'Weekly review report','trigger'=>'weekly','conditions'=>['time'=>'18:00','weekdays'=>[7]],'actions'=>[['type'=>'weekly_report']]],
            'monthly'=>['name'=>'Monthly financial summary','trigger'=>'monthly','conditions'=>['time'=>'08:00'],'actions'=>[['type'=>'monthly_summary']]],
        ];
    }
    public static function install(string $key): int {
        $template=self::templates()[$key] ?? null;
        if (!$template) throw new InvalidArgumentException(t('Choose a starter automation.'));
        [$conditions,$actions]=self::validate($template['trigger'],$template['conditions'],$template['actions']);
        return Db::tx(function () use ($key,$template,$conditions,$actions): int {
            // The write claim is first, so concurrent clicks cannot create two starters.
            Db::run('INSERT OR IGNORE INTO automation_rules(profile_id,template_key,name,enabled,trigger,conditions,actions) VALUES (?,?,?,1,?,?,?)',[self::profileId(),$key,$template['name'],$template['trigger'],json_encode($conditions,JSON_THROW_ON_ERROR),json_encode($actions,JSON_THROW_ON_ERROR)]);
            $rule=Db::one('SELECT id FROM automation_rules WHERE profile_id=? AND template_key=?',[self::profileId(),$key]);
            // Re-enabling a starter retains any schedule, condition or action edits.
            Db::run('UPDATE automation_rules SET enabled=1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$rule['id']]);
            return (int)$rule['id'];
        });
    }
    /** Bounded, transactionally claimed schedule and activity slots. */
    public static function runNow(int $id,?DateTimeImmutable $now=null): array {
        self::rule($id);
        $recent=Db::one("SELECT 1 FROM automation_runs WHERE rule_id=? AND slot LIKE 'manual:%' AND created_at>=datetime('now','-1 minute') LIMIT 1",[$id]);
        if ($recent) throw new InvalidArgumentException(t('This routine was just run. Wait a minute before running it again.'));
        return self::tick($now,$id,true);
    }
    public static function retryRun(int $runId,?DateTimeImmutable $now=null): array {
        $run=Db::one('SELECT r.id,r.rule_id,r.slot,r.status FROM automation_runs r JOIN automation_rules a ON a.id=r.rule_id WHERE r.id=? AND a.profile_id=?',[$runId,self::profileId()]);
        if (!$run || $run['status']!=='failed') throw new InvalidArgumentException(t('Choose a failed run from your profile.'));
        return self::tick($now,(int)$run['rule_id'],false,$run['slot'],true);
    }
    public static function tick(?DateTimeImmutable $now=null,?int $onlyRule=null,bool $manual=false,?string $onlySlot=null,bool $explicitRetry=false): array {
        $out=['ran'=>0,'skipped'=>0,'failed'=>0]; $budget=200;
        if (!self::available('Automation') || !self::table('automation_rules')) return $out;
        $instant=$now ?? new DateTimeImmutable();
        $query='SELECT * FROM automation_rules WHERE profile_id=?'.($onlyRule!==null ? ' AND id=?' : ' AND enabled=1').' ORDER BY id LIMIT 100';
        foreach (Db::all($query,$onlyRule!==null ? [self::profileId(),$onlyRule] : [self::profileId()]) as $rule) {
            try { [$conditions,$actions]=self::validate($rule['trigger'],json_decode($rule['conditions'],true,32,JSON_THROW_ON_ERROR),json_decode($rule['actions'],true,32,JSON_THROW_ON_ERROR)); }
            catch (Throwable $error) { error_log('Invalid automation '.$rule['id']); $out['failed']++; continue; }
            $local=$instant->setTimezone(new DateTimeZone($conditions['timezone']));
            $slots=[];
            if ($onlySlot!==null) {
                $slots[$onlySlot]=preg_match('/^\d{4}-\d{2}-\d{2}$/D',$onlySlot) ? $local->setDate((int)substr($onlySlot,0,4),(int)substr($onlySlot,5,2),(int)substr($onlySlot,8,2)) : $local;
            } elseif ($manual) {
                // A deliberate click gets its own slot while still using the same
                // transactional effect claim and bounded action allowlist.
                $slots['manual:'.bin2hex(random_bytes(12))]=$local;
            } elseif ($conditions['event']!=='') {
                $owner=match($conditions['event']) {'task_completed','habit_checked'=>'Planner','focus_completed'=>'Focus'};
                if (!self::available($owner)) continue;
                foreach (Db::all("SELECT e.* FROM automation_events e WHERE kind=? AND id>? AND created_at>=? AND NOT EXISTS(SELECT 1 FROM automation_runs r WHERE r.rule_id=? AND r.slot='event:'||e.id AND (r.status IN ('complete','skipped') OR (r.status='failed' AND r.attempts>?))) ORDER BY id LIMIT 100",[$conditions['event'],$conditions['event_after'],$rule['created_at'],$rule['id'],$conditions['retry_limit']]) as $event) {
                    $slots['event:'.$event['id']]=$local;
                }
            } else {
                for ($days=$conditions['catch_up_days'];$days>=0;$days--) {
                    $slotTime=$local->modify('-'.$days.' days');
                    if (!in_array((int)$slotTime->format('N'),$conditions['weekdays'],true) || ($rule['trigger']==='monthly' && $slotTime->format('d')!=='01')) continue;
                    if ($days===0 && $local->format('H:i')<$conditions['time']) continue;
                    // Never manufacture runs from before the routine existed.
                    if ($days>0 && $slotTime->format('Y-m-d').' '.$conditions['time'] < (new DateTimeImmutable($rule['created_at'],new DateTimeZone('UTC')))->setTimezone($local->getTimezone())->format('Y-m-d H:i')) continue;
                    $slots[$slotTime->format('Y-m-d')]=$slotTime;
                }
            }
            foreach ($slots as $slot=>$slotTime) {
                if (--$budget<0) return $out;
                $utc=$instant->setTimezone(new DateTimeZone('UTC'));
                $old=Db::one('SELECT * FROM automation_runs WHERE rule_id=? AND slot=?',[$rule['id'],$slot]);
                if ($old && ($old['status']!=='failed' || (!$explicitRetry && ((int)$old['attempts']>$conditions['retry_limit'] || $old['retry_at']===null || $old['retry_at']>$utc->format('Y-m-d H:i:s'))))) continue;
                try {
                    $status=Db::tx(function () use ($rule,$slot,$slotTime,$conditions,$actions,$old): string {
                        if ($old) {
                            Db::run("UPDATE automation_runs SET status='running',attempts=attempts+1,retry_at=NULL WHERE id=? AND status='failed' AND attempts=?",[$old['id'],$old['attempts']]);
                        } else Db::run("INSERT OR IGNORE INTO automation_runs(rule_id,slot,status) VALUES (?,?,'running')",[$rule['id'],$slot]);
                        if (!(int)Db::one('SELECT changes() n')['n']) return 'duplicate';
                        $run=(int)Db::one('SELECT id FROM automation_runs WHERE rule_id=? AND slot=?',[$rule['id'],$slot])['id'];
                        $open=self::available('Planner') && self::table('tasks') ? (int)Db::one("SELECT COUNT(*) n FROM tasks WHERE status='open' AND archived_at IS NULL")['n'] : 0;
                        if ($open<$conditions['min_open_tasks']) { Db::run("UPDATE automation_runs SET status='skipped',detail='Open task condition was not met.' WHERE id=?",[$run]); return 'skipped'; }
                        $done=0;
                        foreach ($actions as $action) {
                            // Same action/period across rules has one effect. Event IDs distinguish activities.
                            $period=match($action['type']) {'weekly_report'=>$slotTime->modify('monday this week')->format('Y-m-d'),'monthly_summary'=>$slotTime->format('Y-m'),default=>$slot};
                            $key=hash('sha256',json_encode([$action,$period,$conditions['timezone']],JSON_THROW_ON_ERROR));
                            Db::run('INSERT OR IGNORE INTO automation_effects(profile_id,effect_key,run_id) VALUES (?,?,?)',[self::profileId(),$key,$run]);
                            if ((int)Db::one('SELECT changes() n')['n']) { self::act($action,$run,$slotTime); $done++; }
                        }
                        Db::run("UPDATE automation_runs SET status='complete',detail=? WHERE id=?",[$done.' action(s) completed; duplicate effects suppressed.',$run]);
                        return 'complete';
                    });
                    if ($status==='complete') $out['ran']++; elseif ($status==='skipped') $out['skipped']++;
                } catch (Throwable $error) {
                    error_log('Automation rule '.$rule['id'].': '.$error->getMessage());
                    $attempts=(int)($old['attempts'] ?? 0)+1;
                    $delay=min(1440,$conditions['retry_minutes'] * (2 ** min(10,max(0,$attempts-1))));
                    $retry=$utc->modify('+'.$delay.' minutes')->format('Y-m-d H:i:s');
                    Db::run("INSERT INTO automation_runs(rule_id,slot,status,detail,retry_at) VALUES (?,?,'failed',?,?) ON CONFLICT(rule_id,slot) DO UPDATE SET status='failed',attempts=attempts+1,detail=excluded.detail,retry_at=excluded.retry_at",[$rule['id'],$slot,'This run failed. Retry follows your routine preferences.',$retry]);
                    $out['failed']++;
                }
            }
        }
        return $out;
    }
    private static function message(int $run,string $title,string $body,string $route='/insights#alerts'): void {
        Db::run('INSERT INTO automation_messages(profile_id,run_id,title,body,route) VALUES (?,?,?,?,?)',[self::profileId(),$run,$title,$body,$route]);
    }
    private static function act(array $action,int $run,DateTimeImmutable $now): void {
        $day=$now->format('Y-m-d');
        switch ($action['type']) {
            case 'notify': self::message($run,$action['title'],$action['body']); break;
            case 'morning_brief':
                $lines=[];
                if (self::available('Planner') && self::table('tasks')) {
                    $tasks=Db::all("SELECT title,due FROM tasks WHERE status='open' AND due<=? ORDER BY due,id LIMIT 8",[$day]);
                    $lines[]=strtr(t('Tasks due or overdue: {count}.'),['{count}'=>count($tasks).(count($tasks)===8 ? '+' : '')]);
                    foreach ($tasks as $task) $lines[]=$task['title'].' ('.$task['due'].')';
                }
                if (self::available('Planner') && self::table('planner_appointments')) foreach (Db::all("SELECT title,starts_at,all_day FROM planner_appointments WHERE status='scheduled' AND ends_at>? AND starts_at<? ORDER BY starts_at LIMIT 8",[$day.' 00:00',$now->modify('+1 day')->format('Y-m-d').' 00:00']) as $event) $lines[]=$event['title'].' · '.($event['all_day'] ? t('All day') : substr($event['starts_at'],11,5));
                if (self::available('Uni') && self::table('timetable')) foreach (Db::all('SELECT m.name,t.start_time FROM timetable t JOIN uni_modules m ON m.id=t.module_id WHERE t.day=? ORDER BY t.start_time LIMIT 8',[(int)$now->format('N')]) as $class) $lines[]=$class['name'].' · '.$class['start_time'];
                if (self::available('Salary') && self::table('financial_obligations')) foreach (self::bills($day,$day) as $bill) $lines[]=t('Payment: ').$bill['title'].' · '.money($bill['amount']);
                if (($weather=WeatherService::brief())!==null) $lines[]=$weather;
                self::message($run,t('Your morning brief').' · '.$day,implode("\n",$lines) ?: t('No saved events or due items today.'),'/planner'); break;
            case 'evening_review': self::message($run,t('Time to close your day'),t('Review what you finished, note any blockers, and choose tomorrow’s priorities.'),'/planner'); break;
            case 'deadline_reminder':
                foreach (self::deadlines($now) as $item) self::message($run,$item['days']===0 ? t('Due today') : strtr(t('Due in {days} day(s)'),['{days}'=>$item['days']]),$item['title'].' · '.$item['due'],$item['route']); break;
            case 'weekly_report':
                // The previous complete Monday–Sunday week, regardless of the chosen run weekday.
                $end=$now->modify('monday this week')->setTime(0,0); self::report($run,'weekly',$end->modify('-7 days'),$end); break;
            case 'monthly_summary':
                $end=$now->modify('first day of this month')->setTime(0,0); self::report($run,'monthly',$end->modify('-1 month'),$end); break;
        }
    }
    private static function bills(string $from,string $to): array {
        return Db::all("SELECT o.* FROM financial_obligations o WHERE o.active=1 AND o.due_date>=? AND o.due_date<=? AND NOT EXISTS(SELECT 1 FROM financial_payments p WHERE p.obligation_id=o.id AND p.due_date=o.due_date AND p.status='paid') ORDER BY o.due_date LIMIT 100",[$from,$to]);
    }
    private static function deadlines(DateTimeImmutable $now): array {
        $out=[];
        foreach ([7,3,1,0] as $days) {
            $due=$now->modify('+'.$days.' days')->format('Y-m-d'); $rows=[];
            if (self::available('Planner') && self::table('tasks')) foreach (Db::all("SELECT title FROM tasks WHERE status='open' AND due=? ORDER BY id LIMIT 50",[$due]) as $row) $rows[]=$row+['route'=>'/planner'];
            if (self::available('Uni') && self::table('assignments')) foreach (Db::all("SELECT title FROM assignments WHERE status<>'done' AND due_date=? ORDER BY id LIMIT 50",[$due]) as $row) $rows[]=$row+['route'=>'/uni'];
            if (self::available('Uni') && self::table('exams')) foreach (Db::all('SELECT m.name title FROM exams e JOIN uni_modules m ON m.id=e.module_id WHERE e.date=? LIMIT 50',[$due]) as $row) $rows[]=['title'=>t('Exam: ').$row['title'],'route'=>'/uni'];
            if (self::available('Salary') && self::table('financial_obligations')) foreach (self::bills($due,$due) as $row) $rows[]=['title'=>$row['title'],'route'=>'/salary/calendar'];
            if(self::available('LifeAdmin')&&self::table('life_subscriptions'))foreach(Db::all("SELECT name title FROM life_subscriptions WHERE status='active' AND renews_on=?",[$due])as $row)$rows[]=$row+['route'=>'/life-admin#subscriptions'];
            if(self::available('LifeAdmin')&&self::table('life_expiries'))foreach(Db::all("SELECT title FROM life_expiries WHERE status='active' AND expires_on=?",[$due])as $row)$rows[]=$row+['route'=>'/life-admin#expiries'];
            foreach ($rows as $row) $out[]=$row+['due'=>$due,'days'=>$days];
        }
        return array_slice($out,0,200);
    }
    private static function report(int $run,string $kind,DateTimeImmutable $start,DateTimeImmutable $end): void {
        $from=$start->format('Y-m-d'); $until=$end->format('Y-m-d');
        $utcFrom=$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $utcUntil=$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $summary=['tasks_done'=>null,'income'=>null,'expenses'=>null,'habit_checkins'=>null,'focus_minutes'=>null];
        if (self::available('Planner') && self::table('tasks')) $summary['tasks_done']=(int)Db::one("SELECT COUNT(*) n FROM tasks WHERE status='done' AND completed_at>=? AND completed_at<?",[$utcFrom,$utcUntil])['n'];
        if (self::available('Salary') && self::table('expenses')) {
            $summary['expenses']=(float)Db::one('SELECT COALESCE(ROUND(SUM(amount),2),0) n FROM expenses WHERE date>=? AND date<?',[$from,$until])['n'];
            $summary['income']=(float)Db::one('SELECT COALESCE(ROUND(SUM(amount),2),0) n FROM income WHERE date>=? AND date<?',[$from,$until])['n'];
        }
        if ($kind==='weekly' && self::available('Planner') && self::table('planner_habit_checks')) $summary['habit_checkins']=(int)Db::one('SELECT COUNT(*) n FROM planner_habit_checks WHERE date>=? AND date<?',[$from,$until])['n'];
        if ($kind==='weekly' && self::available('Focus') && self::table('focus_sessions')) $summary['focus_minutes']=round((float)Db::one("SELECT COALESCE(SUM(elapsed_seconds),0)/60.0 n FROM focus_sessions WHERE kind='study' AND status='complete' AND completed_at>=? AND completed_at<?",[$utcFrom,$utcUntil])['n'],1);
        $id=Db::run('INSERT INTO automation_reports(profile_id,run_id,kind,starts_on,ends_before,summary) VALUES (?,?,?,?,?,?)',[self::profileId(),$run,$kind,$from,$until,json_encode($summary,JSON_THROW_ON_ERROR)]);
        self::message($run,$kind==='weekly' ? t('Your weekly report is ready') : t('Your monthly money summary is ready'),$from.' to '.$end->modify('-1 day')->format('Y-m-d').' · '.t('Open to review or print.'),'/insights/reports/'.$id);
    }
    public static function alerts(): array {
        if (!self::available('Automation') || !self::table('automation_messages')) return [];
        return array_map(fn($row)=>['id'=>hash('sha256','automation:'.$row['id']),'title'=>$row['title'],'body'=>$row['body'],'route'=>$row['route']==='/automation' ? '/insights#alerts' : preg_replace('~^/automation/reports/(\d+)$~','/insights/reports/$1',$row['route']),'level'=>'info','kind'=>'reminder','created_at'=>$row['created_at']],Db::all('SELECT * FROM automation_messages WHERE profile_id=? ORDER BY id DESC LIMIT 50',[self::profileId()]));
    }
    public static function reportById(int $id): array {
        $report=Db::one('SELECT * FROM automation_reports WHERE id=? AND profile_id=?',[$id,self::profileId()]);
        if (!$report) throw new InvalidArgumentException(t('Report not found.'));
        $report['summary']=json_decode($report['summary'],true,32,JSON_THROW_ON_ERROR); return $report;
    }
}

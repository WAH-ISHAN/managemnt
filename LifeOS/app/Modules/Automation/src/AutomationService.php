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
        if (!is_string($value) || str_contains($value,"\0") || mb_strlen(trim($value))>$max || ($required && trim($value)==='')) throw new InvalidArgumentException('Enter valid automation text (up to '.$max.' characters).');
        return trim($value);
    }
    public static function validate(string $trigger,array $conditions,array $actions): array {
        if (!in_array($trigger,['daily','weekly','monthly','deadline'],true)) throw new InvalidArgumentException('Choose a supported trigger.');
        if (array_diff(array_keys($conditions),['time','weekdays','min_open_tasks'])) throw new InvalidArgumentException('Unsupported automation condition.');
        $time=$conditions['time'] ?? '07:00';
        if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time)) throw new InvalidArgumentException('Choose a valid local time.');
        $days=$conditions['weekdays'] ?? ($trigger==='weekly' ? [1] : [1,2,3,4,5,6,7]);
        if (!is_array($days) || !$days || count($days)>7) throw new InvalidArgumentException('Choose at least one weekday.');
        $days=array_values(array_unique(array_map(fn($day)=>int_range($day,1,7),$days))); sort($days);
        $minimum=int_range($conditions['min_open_tasks'] ?? 0,0,10000);
        if (!$actions || count($actions)>5 || !array_is_list($actions)) throw new InvalidArgumentException('Choose between one and five actions.');
        $clean=[];
        foreach ($actions as $action) {
            if (!is_array($action) || array_diff(array_keys($action),['type','title','body'])) throw new InvalidArgumentException('Unsupported automation action.');
            $type=$action['type'] ?? '';
            if (!is_string($type) || !isset(self::ACTIONS[$type])) throw new InvalidArgumentException('Choose a supported action.');
            if (($trigger==='deadline')!==($type==='deadline_reminder')) throw new InvalidArgumentException('Use the deadline trigger with the deadline ladder action.');
            if ($type==='weekly_report' && $trigger!=='weekly') throw new InvalidArgumentException('Weekly reports need a weekly trigger.');
            if ($type==='monthly_summary' && $trigger!=='monthly') throw new InvalidArgumentException('Monthly summaries need a monthly trigger.');
            if (in_array($type,array_column($clean,'type'),true)) throw new InvalidArgumentException('Choose each action only once.');
            $clean[]=$type==='notify' ? ['type'=>$type,'title'=>self::text($action['title'] ?? '',150),'body'=>self::text($action['body'] ?? '',1000)] : ['type'=>$type];
        }
        return [['time'=>$time,'weekdays'=>$days,'min_open_tasks'=>$minimum],$clean];
    }
    public static function save(?int $id,string $name,string $trigger,array $conditions,array $actions,bool $enabled=false): int {
        $name=self::text($name,150); [$conditions,$actions]=self::validate($trigger,$conditions,$actions);
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
        if (!$row) throw new InvalidArgumentException('Automation rule not found.'); return $row;
    }
    public static function toggle(int $id,bool $enabled): void { self::rule($id); Db::run('UPDATE automation_rules SET enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$enabled ? 1 : 0,$id]); }
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
        if (!$template) throw new InvalidArgumentException('Choose a starter automation.');
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
    /** Runs today's eligible slots once; historical days are deliberately not replayed. */
    public static function tick(?DateTimeImmutable $now=null): array {
        $out=['ran'=>0,'skipped'=>0,'failed'=>0];
        if (!self::available('Automation') || !self::table('automation_rules')) return $out;
        $now=self::local($now); $day=$now->format('Y-m-d');
        foreach (Db::all('SELECT * FROM automation_rules WHERE profile_id=? AND enabled=1 ORDER BY id LIMIT 100',[self::profileId()]) as $rule) {
            $slot=$day;
            try {
                [$conditions,$actions]=self::validate($rule['trigger'],json_decode($rule['conditions'],true,32,JSON_THROW_ON_ERROR),json_decode($rule['actions'],true,32,JSON_THROW_ON_ERROR));
                if ($now->format('H:i')<$conditions['time'] || !in_array((int)$now->format('N'),$conditions['weekdays'],true) || ($rule['trigger']==='monthly' && $now->format('d')!=='01')) continue;
                if (Db::one('SELECT 1 FROM automation_runs WHERE rule_id=? AND slot=?',[$rule['id'],$slot])) continue;
                $status=Db::tx(function () use ($rule,$slot,$conditions,$actions,$now): string {
                    Db::run("INSERT OR IGNORE INTO automation_runs(rule_id,slot,status) VALUES (?,?,'running')",[$rule['id'],$slot]);
                    if (!(int)Db::one('SELECT changes() n')['n']) return 'duplicate';
                    $run=(int)Db::one('SELECT id FROM automation_runs WHERE rule_id=? AND slot=?',[$rule['id'],$slot])['id'];
                    $open=self::available('Planner') && self::table('tasks') ? (int)Db::one("SELECT COUNT(*) n FROM tasks WHERE status='open'")['n'] : 0;
                    if ($open<$conditions['min_open_tasks']) { Db::run("UPDATE automation_runs SET status='skipped',detail='Open task condition was not met.' WHERE id=?",[$run]); return 'skipped'; }
                    foreach ($actions as $action) self::act($action,$run,$now);
                    Db::run("UPDATE automation_runs SET status='complete',detail=? WHERE id=?",[count($actions).' action(s) completed.',$run]);
                    return 'complete';
                });
                if ($status==='complete') $out['ran']++; elseif ($status==='skipped') $out['skipped']++;
            } catch (Throwable $e) {
                // A failed action rolls back all its effects; leave a visible failure record.
                error_log('Automation rule '.$rule['id'].': '.$e->getMessage());
                Db::run("INSERT OR IGNORE INTO automation_runs(rule_id,slot,status,detail) VALUES (?,?,'failed',?)",[$rule['id'],$slot,'This run failed. Review the rule and local error log.']);
                $out['failed']++;
            }
        }
        return $out;
    }
    private static function message(int $run,string $title,string $body,string $route='/automation'): void {
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
                    $lines[]='Tasks due or overdue: '.count($tasks).(count($tasks)===8 ? '+' : '').'.';
                    foreach ($tasks as $task) $lines[]=$task['title'].' ('.$task['due'].')';
                }
                if (self::available('Planner') && self::table('planner_appointments')) foreach (Db::all("SELECT title,starts_at FROM planner_appointments WHERE status='scheduled' AND starts_at>=? AND starts_at<? ORDER BY starts_at LIMIT 8",[$day.' 00:00',$now->modify('+1 day')->format('Y-m-d').' 00:00']) as $event) $lines[]=$event['title'].' · '.substr($event['starts_at'],11,5);
                if (self::available('Uni') && self::table('timetable')) foreach (Db::all('SELECT m.name,t.start_time FROM timetable t JOIN uni_modules m ON m.id=t.module_id WHERE t.day=? ORDER BY t.start_time LIMIT 8',[(int)$now->format('N')]) as $class) $lines[]=$class['name'].' · '.$class['start_time'];
                if (self::available('Salary') && self::table('financial_obligations')) foreach (self::bills($day,$day) as $bill) $lines[]='Payment: '.$bill['title'].' · '.money($bill['amount']);
                self::message($run,'Your morning brief · '.$day,implode("\n",$lines) ?: 'No saved events or due items today.','/planner'); break;
            case 'evening_review': self::message($run,'Time to close your day','Review what you finished, note any blockers, and choose tomorrow’s priorities.','/planner'); break;
            case 'deadline_reminder':
                foreach (self::deadlines($now) as $item) self::message($run,$item['days']===0 ? 'Due today' : 'Due in '.$item['days'].' day(s)',$item['title'].' · '.$item['due'],$item['route']); break;
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
            if (self::available('Uni') && self::table('exams')) foreach (Db::all('SELECT m.name title FROM exams e JOIN uni_modules m ON m.id=e.module_id WHERE e.date=? LIMIT 50',[$due]) as $row) $rows[]=['title'=>'Exam: '.$row['title'],'route'=>'/uni'];
            if (self::available('Salary') && self::table('financial_obligations')) foreach (self::bills($due,$due) as $row) $rows[]=['title'=>$row['title'],'route'=>'/salary/calendar'];
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
        self::message($run,$kind==='weekly' ? 'Your weekly report is ready' : 'Your monthly money summary is ready',$from.' to '.$end->modify('-1 day')->format('Y-m-d').' · Open to review or print.','/automation/reports/'.$id);
    }
    public static function alerts(): array {
        if (!self::available('Automation') || !self::table('automation_messages')) return [];
        return array_map(fn($row)=>['id'=>hash('sha256','automation:'.$row['id']),'title'=>$row['title'],'body'=>$row['body'],'route'=>$row['route'],'level'=>'info','kind'=>'reminder','created_at'=>$row['created_at']],Db::all('SELECT * FROM automation_messages WHERE profile_id=? ORDER BY id DESC LIMIT 50',[self::profileId()]));
    }
    public static function reportById(int $id): array {
        $report=Db::one('SELECT * FROM automation_reports WHERE id=? AND profile_id=?',[$id,self::profileId()]);
        if (!$report) throw new InvalidArgumentException('Report not found.');
        $report['summary']=json_decode($report['summary'],true,32,JSON_THROW_ON_ERROR); return $report;
    }
}

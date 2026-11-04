<?php
declare(strict_types=1);
final class FocusService {
    public static function active(): ?array { return Db::one("SELECT * FROM focus_sessions WHERE status IN ('running','paused') LIMIT 1"); }
    public static function elapsed(array $row,?int $now=null): int {
        $n=(int)$row['elapsed_seconds'];
        if ($row['status']==='running') $n+=max(0,($now ?? time())-(int)$row['segment_started']);
        return min((int)$row['target_minutes']*60,$n);
    }
    public static function start(string $subject,int $minutes,string $kind,?int $now=null,array $links=[]): int {
        if (trim($subject)==='' || mb_strlen($subject)>150 || $minutes<1 || $minutes>120 || !in_array($kind,['study','break'],true)) throw new InvalidArgumentException(t('Choose a subject, duration from 1 to 120 minutes, and a valid session type.'));
        $task=isset($links['task_id']) && $links['task_id']!=='' ? record_id($links['task_id']) : null;
        $habit=isset($links['habit_id']) && $links['habit_id']!=='' ? record_id($links['habit_id']) : null;
        $minimum=int_range($links['minimum_minutes'] ?? 25,1,120);
        if (($task || $habit) && class_exists('PersonaService') && !PersonaService::enabled('Planner')) throw new InvalidArgumentException(t('Enable Planner to link focus events.'));
        if ($task && !Db::one("SELECT 1 FROM tasks WHERE id=? AND status='open'",[$task])) throw new InvalidArgumentException(t('Choose an open task.'));
        if ($habit && !Db::one('SELECT 1 FROM planner_habits WHERE id=? AND active=1',[$habit])) throw new InvalidArgumentException(t('Choose an active habit.'));
        return Db::tx(function () use ($subject,$minutes,$kind,$now,$task,$habit,$minimum) {
            if (self::active()) throw new InvalidArgumentException(t('Finish or cancel your current session first.'));
            return Db::run('INSERT INTO focus_sessions(subject,kind,target_minutes,segment_started,task_id,habit_id,habit_minimum_minutes) VALUES (?,?,?,?,?,?,?)',[trim($subject),$kind,$minutes,$now ?? time(),$task,$habit,$minimum]);
        });
    }
    public static function action(int $id,string $action,?int $now=null): void {
        if (!in_array($action,['pause','resume','finish','cancel'],true)) throw new InvalidArgumentException(t('Choose a valid timer action.'));
        Db::tx(function () use ($id,$action,$now) {
            $row=Db::one('SELECT * FROM focus_sessions WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException(t('Session not found.'));
            if (in_array($row['status'],['complete','cancelled'],true)) return;
            $elapsed=self::elapsed($row,$now);
            $status=match($action) {'pause'=>'paused','resume'=>'running','finish'=>'complete','cancel'=>'cancelled'};
            Db::run('UPDATE focus_sessions SET status=?,elapsed_seconds=?,segment_started=?,completed_at=? WHERE id=?',[$status,$elapsed,$status==='running' ? ($now ?? time()) : null,in_array($status,['complete','cancelled'],true) ? gmdate('Y-m-d H:i:s',$now ?? time()) : null,$id]);
            if ($status==='complete' && $row['kind']==='study') {
                if ($row['task_id'] && $elapsed>0) Db::run('INSERT OR IGNORE INTO focus_task_logs(session_id,task_id,seconds) VALUES (?,?,?)',[$id,$row['task_id'],$elapsed]);
                if ($row['habit_id'] && $elapsed>=(int)$row['habit_minimum_minutes']*60 && Db::one('SELECT 1 FROM planner_habits WHERE id=? AND active=1',[$row['habit_id']])) {
                    $day=(new DateTimeImmutable('@'.($now ?? time())))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
                    Db::run('INSERT OR IGNORE INTO planner_habit_checks(habit_id,date) VALUES (?,?)',[$row['habit_id'],$day]);
                    Db::run('INSERT OR IGNORE INTO focus_habit_events(session_id,habit_id,date,seconds) VALUES (?,?,?,?)',[$id,$row['habit_id'],$day,$elapsed]);
                }
            }
        });
    }
    public static function minutes(string $start,string $end): float { return round((float)Db::one("SELECT COALESCE(SUM(elapsed_seconds),0)/60.0 n FROM focus_sessions WHERE kind='study' AND status='complete' AND completed_at>=? AND completed_at<?",[$start,$end])['n'],1); }
}

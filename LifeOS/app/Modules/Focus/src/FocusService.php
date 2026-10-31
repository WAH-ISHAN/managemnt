<?php
declare(strict_types=1);
final class FocusService {
    public static function active(): ?array { return Db::one("SELECT * FROM focus_sessions WHERE status IN ('running','paused') LIMIT 1"); }
    public static function elapsed(array $row,?int $now=null): int {
        $n=(int)$row['elapsed_seconds'];
        if ($row['status']==='running') $n+=max(0,($now ?? time())-(int)$row['segment_started']);
        return min((int)$row['target_minutes']*60,$n);
    }
    public static function start(string $subject,int $minutes,string $kind,?int $now=null): int {
        if (trim($subject)==='' || mb_strlen($subject)>150 || $minutes<1 || $minutes>120 || !in_array($kind,['study','break'],true)) throw new InvalidArgumentException('Choose a subject, duration from 1 to 120 minutes, and a valid session type.');
        return Db::tx(function () use ($subject,$minutes,$kind,$now) {
            if (self::active()) throw new InvalidArgumentException('Finish or cancel your current session first.');
            return Db::run('INSERT INTO focus_sessions(subject,kind,target_minutes,segment_started) VALUES (?,?,?,?)',[trim($subject),$kind,$minutes,$now ?? time()]);
        });
    }
    public static function action(int $id,string $action,?int $now=null): void {
        if (!in_array($action,['pause','resume','finish','cancel'],true)) throw new InvalidArgumentException('Choose a valid timer action.');
        Db::tx(function () use ($id,$action,$now) {
            $row=Db::one('SELECT * FROM focus_sessions WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Session not found.');
            if (in_array($row['status'],['complete','cancelled'],true)) return;
            $elapsed=self::elapsed($row,$now);
            $status=match($action) {'pause'=>'paused','resume'=>'running','finish'=>'complete','cancel'=>'cancelled'};
            Db::run('UPDATE focus_sessions SET status=?,elapsed_seconds=?,segment_started=?,completed_at=? WHERE id=?',[$status,$elapsed,$status==='running' ? ($now ?? time()) : null,in_array($status,['complete','cancelled'],true) ? gmdate('Y-m-d H:i:s',$now ?? time()) : null,$id]);
        });
    }
    public static function minutes(string $start,string $end): float { return round((float)Db::one("SELECT COALESCE(SUM(elapsed_seconds),0)/60.0 n FROM focus_sessions WHERE kind='study' AND status='complete' AND completed_at>=? AND completed_at<?",[$start,$end])['n'],1); }
}

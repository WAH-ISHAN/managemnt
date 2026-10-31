<?php
declare(strict_types=1);
final class LessonService {
    public const AREAS=['Uni','Money','Business','Trading','Health','Habits','Other'];
    public static function add(array $d): int {
        if (!in_array($d['area'] ?? '',self::AREAS,true) || trim($d['what_happened'] ?? '')==='') throw new InvalidArgumentException('Choose an area and describe what happened.');
        $due=optional_date($d['due'] ?? '');
        return Db::tx(function () use ($d,$due) {
            $task=null; $action=trim($d['action'] ?? '');
            if ($action!=='') $task=Db::run('INSERT INTO tasks(title,due,source) VALUES (?,?,?)',[$action,$due,'lesson']);
            return Db::run('INSERT INTO lessons(area,what_happened,root_cause,lesson,action,task_id) VALUES (?,?,?,?,?,?)',[$d['area'],trim($d['what_happened']),trim($d['root_cause'] ?? ''),trim($d['lesson'] ?? ''),$action,$task]);
        });
    }
    public static function repeated(): array { return Db::all("SELECT area,COUNT(*) n FROM lessons WHERE created_at>=datetime('now','-90 day') GROUP BY area HAVING COUNT(*)>=2 ORDER BY n DESC,area"); }
    public static function completeTask(int $id): void {
        Db::tx(function () use ($id) {
            if (!Db::one('SELECT id FROM tasks WHERE id=?',[$id])) throw new InvalidArgumentException('Task not found.');
            Db::run("UPDATE tasks SET status='done' WHERE id=?",[$id]);
            Db::run("UPDATE lessons SET status='applied' WHERE task_id=? AND status='open'",[$id]);
        });
    }
    public static function reopenTask(int $id): void {
        Db::tx(function () use ($id) { Db::run("UPDATE tasks SET status='open' WHERE id=?",[$id]); Db::run("UPDATE lessons SET status='open' WHERE task_id=?",[$id]); });
    }
    public static function status(int $id,string $status): void {
        if (!in_array($status,['open','applied','verified'],true)) throw new InvalidArgumentException('Choose a valid lesson status.');
        Db::tx(function () use ($id,$status) {
            $row=Db::one('SELECT * FROM lessons WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Lesson not found.');
            if ($row['task_id']) {
                $task=Db::one('SELECT * FROM tasks WHERE id=?',[$row['task_id']]);
                if ($status!=='open' && $task['status']!=='done') throw new InvalidArgumentException('Complete the lesson’s action task before marking it applied or verified.');
                if ($status==='open') Db::run("UPDATE tasks SET status='open' WHERE id=?",[$row['task_id']]);
            }
            Db::run('UPDATE lessons SET status=? WHERE id=?',[$status,$id]);
        });
    }
    public static function delete(int $id): void {
        Db::tx(function () use ($id) { $row=Db::one('SELECT task_id FROM lessons WHERE id=?',[$id]); Db::run('DELETE FROM lessons WHERE id=?',[$id]); if ($row && $row['task_id']) Db::run("DELETE FROM tasks WHERE id=? AND source='lesson'",[$row['task_id']]); });
    }
    public static function weekId(?int $time=null): string { return date('o-\WW',$time ?? time()); }
    public static function review(array $data): void {
        $score=int_range($data['score'] ?? '',1,10);
        Db::run('INSERT INTO weekly_reviews(week,wins,fails,score,focus) VALUES (?,?,?,?,?) ON CONFLICT(week) DO UPDATE SET wins=excluded.wins,fails=excluded.fails,score=excluded.score,focus=excluded.focus',[self::weekId(),$data['wins'],$data['fails'],$score,$data['focus']]);
    }
}

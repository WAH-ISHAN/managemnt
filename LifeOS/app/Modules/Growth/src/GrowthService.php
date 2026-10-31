<?php
declare(strict_types=1);
final class GrowthService {
    public const CATEGORIES=['personal'=>'Personal','education'=>'Education','career'=>'Career','health'=>'Health','skills'=>'Skill development','money'=>'Money'];
    public static function goal(int $id): array { $row=Db::one('SELECT * FROM personal_goals WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Goal not found.'); return $row; }
    public static function step(int $id): array { $row=Db::one('SELECT * FROM personal_goal_steps WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Goal step not found.'); return $row; }
    private static function title(mixed $value): string { if (!is_string($value) || trim($value)==='' || mb_strlen(trim($value))>200) throw new InvalidArgumentException('Enter a title of up to 200 characters.'); return trim($value); }
    private static function text(mixed $value,int $max): string { if (!is_string($value) || mb_strlen($value)>$max) throw new InvalidArgumentException('Enter text of up to '.$max.' characters.'); return trim($value); }
    private static function date(mixed $value): ?string { if ($value===null || $value==='') return null; if (!is_string($value)) throw new InvalidArgumentException('Choose a valid goal date.'); return valid_date($value); }
    private static function flag(mixed $value): int { if (!in_array($value,['0','1',0,1,false,true],true)) throw new InvalidArgumentException('Invalid milestone flag.'); return in_array($value,['1',1,true],true) ? 1 : 0; }
    private static function cycle(string $table,?int $id,?int $dependency): void {
        $visited=[]; while ($dependency) {
            if ($dependency===$id || isset($visited[$dependency])) throw new InvalidArgumentException('Dependencies cannot form a cycle.'); $visited[$dependency]=true;
            $row=Db::one('SELECT depends_on FROM '.$table.' WHERE id=?',[$dependency]); if (!$row) throw new InvalidArgumentException('The selected dependency does not exist.');
            $dependency=$row['depends_on'] ? (int)$row['depends_on'] : null;
        }
    }
    public static function saveGoal(?int $id,array $d): int {
        $title=self::title($d['title'] ?? ''); $category=$d['category'] ?? 'personal'; $horizon=$d['horizon'] ?? 'short'; $status=$d['status'] ?? 'active'; $due=self::date($d['due_date'] ?? ''); $dependency=optional_id($d['depends_on'] ?? ''); $notes=self::text($d['notes'] ?? '',5000);
        if (!is_string($category) || !isset(self::CATEGORIES[$category]) || !is_string($horizon) || !in_array($horizon,['short','long'],true) || !is_string($status) || !in_array($status,['active','paused','done'],true)) throw new InvalidArgumentException('Choose valid goal details.');
        if ($id) self::goal($id); self::cycle('personal_goals',$id,$dependency);
        if ($status==='done') {
            if ($dependency && self::goal($dependency)['status']!=='done') throw new InvalidArgumentException('Finish the prerequisite goal first.');
            if ($id && Db::one("SELECT id FROM personal_goal_steps WHERE goal_id=? AND status='open' LIMIT 1",[$id])) throw new InvalidArgumentException('Complete all goal steps before finishing the goal.');
        } elseif ($id && Db::one("SELECT id FROM personal_goals WHERE depends_on=? AND status='done' LIMIT 1",[$id])) throw new InvalidArgumentException('Reopen dependent completed goals before reopening this prerequisite.');
        $values=[$title,$category,$horizon,$due,$notes,$status,$dependency];
        if ($id) { Db::run('UPDATE personal_goals SET title=?,category=?,horizon=?,due_date=?,notes=?,status=?,depends_on=? WHERE id=?',[...$values,$id]); return $id; }
        return Db::run('INSERT INTO personal_goals(title,category,horizon,due_date,notes,status,depends_on) VALUES (?,?,?,?,?,?,?)',$values);
    }
    public static function saveStep(int $goalId,?int $id,array $d): int {
        $goal=self::goal($goalId); if ($goal['status']==='done') throw new InvalidArgumentException('Reopen this goal before changing its steps.');
        if ($id && (int)self::step($id)['goal_id']!==$goalId) throw new InvalidArgumentException('This step belongs to a different goal.');
        $title=self::title($d['title'] ?? ''); $due=self::date($d['due_date'] ?? ''); $dependency=optional_id($d['depends_on'] ?? '');
        if ($dependency && (int)self::step($dependency)['goal_id']!==$goalId) throw new InvalidArgumentException('Choose a prerequisite step from the same goal.');
        self::cycle('personal_goal_steps',$id,$dependency);
        if ($id && self::step($id)['status']==='done' && $dependency && self::step($dependency)['status']!=='done') throw new InvalidArgumentException('A completed step cannot depend on an unfinished step.');
        $values=[$title,$due,self::flag($d['is_milestone'] ?? 0),$dependency];
        if ($id) { Db::run('UPDATE personal_goal_steps SET title=?,due_date=?,is_milestone=?,depends_on=? WHERE id=?',[...$values,$id]); return $id; }
        return Db::run('INSERT INTO personal_goal_steps(title,due_date,is_milestone,depends_on,goal_id) VALUES (?,?,?,?,?)',[...$values,$goalId]);
    }
    public static function stepStatus(int $goalId,int $id,string $status): void {
        $goal=self::goal($goalId); $step=self::step($id);
        if ((int)$step['goal_id']!==$goalId || !in_array($status,['open','done'],true)) throw new InvalidArgumentException('Invalid goal step.');
        if ($goal['status']==='done') throw new InvalidArgumentException('Reopen this goal before changing its steps.');
        if ($status==='done' && $step['depends_on'] && self::step((int)$step['depends_on'])['status']!=='done') throw new InvalidArgumentException('Finish the prerequisite step first.');
        if ($status==='open' && Db::one("SELECT id FROM personal_goal_steps WHERE depends_on=? AND status='done' LIMIT 1",[$id])) throw new InvalidArgumentException('Reopen completed dependent steps first.');
        Db::run('UPDATE personal_goal_steps SET status=? WHERE id=?',[$status,$id]);
    }
    public static function deleteStep(int $goalId,int $id): void { $goal=self::goal($goalId); $step=self::step($id); if ((int)$step['goal_id']!==$goalId || $goal['status']==='done') throw new InvalidArgumentException('Reopen the matching goal before removing a step.'); Db::run('DELETE FROM personal_goal_steps WHERE id=?',[$id]); }
    public static function goals(): array { return Db::all("SELECT g.*,p.title prerequisite,p.status prerequisite_status,(SELECT COUNT(*) FROM personal_goal_steps s WHERE s.goal_id=g.id) step_count,(SELECT COUNT(*) FROM personal_goal_steps s WHERE s.goal_id=g.id AND s.status='done') done_count FROM personal_goals g LEFT JOIN personal_goals p ON p.id=g.depends_on ORDER BY (g.status='done'),g.due_date IS NULL,g.due_date,g.id DESC"); }
    public static function progress(array $goal): int { return $goal['status']==='done' ? 100 : ((int)$goal['step_count']>0 ? (int)round($goal['done_count']/$goal['step_count']*100) : 0); }
}

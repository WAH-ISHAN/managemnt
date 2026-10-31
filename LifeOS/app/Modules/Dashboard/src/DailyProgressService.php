<?php
declare(strict_types=1);
final class DailyProgressService {
    public static function date(mixed $date): string {
        if (!is_string($date)) throw new InvalidArgumentException('Choose a valid day.');
        valid_date($date);
        if ($date>date('Y-m-d')) throw new InvalidArgumentException('Choose today or an earlier day to review actual activity.');
        return $date;
    }
    public static function day(string $date): array {
        self::date($date);
        $tables=array_column(Db::all("SELECT name FROM sqlite_master WHERE type='table'"),'name');
        $has=fn(string $table)=>in_array($table,$tables,true);
        $local=new DateTimeImmutable($date,new DateTimeZone('Asia/Colombo'));
        $start=$local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end=$local->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $count=fn(string $sql,array $args)=>(int)(Db::one($sql,$args)['n'] ?? 0);
        $tasks=$has('tasks') ? $count("SELECT COUNT(*) n FROM tasks WHERE status='done' AND completed_at>=? AND completed_at<?",[$start,$end]) : 0;
        $steps=$has('personal_goal_steps') ? $count("SELECT COUNT(*) n FROM personal_goal_steps WHERE status='done' AND completed_at>=? AND completed_at<?",[$start,$end]) : 0;
        $focus=$has('focus_sessions') ? Db::one("SELECT COUNT(*) n,COALESCE(SUM(elapsed_seconds),0)/60.0 minutes FROM focus_sessions WHERE status='complete' AND kind='study' AND completed_at>=? AND completed_at<?",[$start,$end]) : ['n'=>0,'minutes'=>0];
        $personal=$has('freedom_time') ? Db::one('SELECT COUNT(*) n,COALESCE(SUM(minutes),0) minutes FROM freedom_time WHERE date=?',[$date]) : ['n'=>0,'minutes'=>0];
        $spending=$has('expenses') ? Db::one('SELECT COUNT(*) n,COALESCE(SUM(amount),0) amount FROM expenses WHERE date=?',[$date]) : ['n'=>0,'amount'=>0];
        $income=$has('income') ? Db::one('SELECT COUNT(*) n,COALESCE(SUM(amount),0) amount FROM income WHERE date=?',[$date]) : ['n'=>0,'amount'=>0];
        $habits=$has('planner_habit_checks') ? $count('SELECT COUNT(*) n FROM planner_habit_checks WHERE date=?',[$date]) : 0;
        return ['date'=>$date,'tasks'=>$tasks,'goal_steps'=>$steps,'focus_minutes'=>round((float)$focus['minutes'],1),'personal_minutes'=>(int)$personal['minutes'],'expenses'=>(float)$spending['amount'],'expense_count'=>(int)$spending['n'],'income'=>(float)$income['amount'],'income_count'=>(int)$income['n'],'habits'=>$habits,'has_activity'=>$tasks+$steps+(int)$focus['n']+(int)$personal['n']+(int)$spending['n']+(int)$income['n']+$habits>0];
    }
    public static function week(string $ending): array {
        self::date($ending); $end=new DateTimeImmutable($ending); $days=[];
        for ($i=6;$i>=0;$i--) $days[]=self::day($end->modify('-'.$i.' day')->format('Y-m-d'));
        return $days;
    }
    public static function suggestions(): array {
        $today=date('Y-m-d'); $soon=date('Y-m-d',strtotime('+7 days')); $items=[];
        foreach (Db::all("SELECT title,due FROM tasks WHERE status='open' AND due IS NOT NULL AND due<=? ORDER BY due,id LIMIT 3",[$soon]) as $row) $items[]=['title'=>$row['title'],'date'=>$row['due'],'kind'=>'Task','route'=>'/planner?view=tasks'];
        if (class_exists('UniService')) foreach (array_slice(UniService::upcoming(7),0,3) as $row) $items[]=['title'=>$row['title'],'date'=>$row['due_date'],'kind'=>'Assignment','route'=>'/uni'];
        if (class_exists('UniService')) foreach (array_slice(UniService::exams(7),0,3) as $row) $items[]=['title'=>$row['module'],'date'=>$row['date'],'kind'=>'Exam','route'=>'/uni?view=exams'];
        if (class_exists('PlannerService')) foreach (array_slice(PlannerService::upcoming(2),0,3) as $row) $items[]=['title'=>$row['title'],'date'=>substr($row['starts_at'],0,10),'kind'=>'Appointment','route'=>'/planner?view=appointments'];
        if (class_exists('FinancePlanningService')) foreach (array_slice(FinancePlanningService::dueItems(7),0,3) as $row) $items[]=['title'=>$row['title'],'date'=>$row['due_date'],'kind'=>'Payment','route'=>'/salary/calendar'];
        usort($items,fn($a,$b)=>strcmp($a['date'],$b['date']));
        return array_slice(array_map(function ($item) use ($today) { $item['when']=$item['date']<$today ? 'Overdue' : ($item['date']===$today ? 'Today' : (new DateTimeImmutable($item['date']))->format('D, j M')); return $item; },$items),0,3);
    }
}

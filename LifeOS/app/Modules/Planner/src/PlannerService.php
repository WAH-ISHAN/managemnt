<?php
declare(strict_types=1);
final class PlannerService {
    public static function taskList(mixed $filter='all',mixed $search='',?string $date=null): array {
        if (!is_string($filter) || !in_array($filter,['all','open','overdue','done'],true)) throw new InvalidArgumentException('Choose a valid task filter.');
        $search=self::text($search,200); $date=self::dateInput($date ?? date('Y-m-d'));
        $where=[]; $params=[];
        if ($filter==='overdue') { $where[]="status='open' AND due<?"; $params[]=$date; }
        elseif ($filter!=='all') { $where[]='status=?'; $params[]=$filter; }
        if ($search!=='') { $where[]="title LIKE ? ESCAPE '!'"; $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%'; }
        $clause=$where ? ' WHERE '.implode(' AND ',$where) : '';
        return ['filter'=>$filter,'search'=>$search,'total'=>(int)Db::one('SELECT COUNT(*) n FROM tasks'.$clause,$params)['n'],
            'rows'=>Db::all("SELECT * FROM tasks".$clause." ORDER BY (status='done'),due IS NULL,due,id DESC LIMIT 200",$params)];
    }
    public static function taskReturnUrl(array $data): string {
        $view=$data['return_view'] ?? 'tasks';
        if (!is_string($view) || !in_array($view,['today','tasks'],true)) throw new InvalidArgumentException('Choose a valid return view.');
        $date=self::dateInput($data['return_date'] ?? date('Y-m-d'));
        $filter=$data['return_filter'] ?? 'all'; $search=self::text($data['return_q'] ?? '',200);
        if (!is_string($filter) || !in_array($filter,['all','open','overdue','done'],true)) throw new InvalidArgumentException('Choose a valid task filter.');
        return '/planner?'.http_build_query(['view'=>$view,'date'=>$date,'filter'=>$filter,'q'=>$search]);
    }
    public const DAYS=[1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'];
    private static function text(mixed $value,int $max): string { if (!is_string($value) || mb_strlen($value)>$max) throw new InvalidArgumentException('Enter text of up to '.$max.' characters.'); return trim($value); }
    private static function flag(array $d,string $key): int { $v=$d[$key] ?? '0'; if (!in_array($v,['0','1',0,1,false,true],true)) throw new InvalidArgumentException('Invalid checkbox value.'); return in_array($v,['1',1,true],true) ? 1 : 0; }
    public static function dateInput(mixed $value,bool $optional=false): ?string { if ($optional && ($value==='' || $value===null)) return null; if (!is_string($value)) throw new InvalidArgumentException('Choose a valid date.'); return valid_date($value); }
    public static function title(mixed $value,int $max=200): string { if (!is_string($value) || trim($value)==='' || mb_strlen(trim($value))>$max) throw new InvalidArgumentException('Enter a title of up to '.$max.' characters.'); return trim($value); }
    public static function task(int $id): array { $row=Db::one('SELECT * FROM tasks WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Task not found.'); return $row; }
    public static function saveTask(?int $id,array $d): int {
        $title=self::title($d['title'] ?? ''); $due=self::dateInput($d['due'] ?? '',true);
        if ($id) { $task=self::task($id); Db::tx(function () use ($id,$title,$due,$task) { Db::run('UPDATE tasks SET title=?,due=? WHERE id=?',[$title,$due,$id]); if ($task['source']==='lesson' && class_exists('LessonService')) Db::run('UPDATE lessons SET action=? WHERE task_id=?',[$title,$id]); }); return $id; }
        return Db::run('INSERT INTO tasks(title,due,source) VALUES (?,?,?)',[$title,$due,'planner']);
    }
    public static function taskStatus(int $id,string $status): void {
        self::task($id); if (!in_array($status,['open','done'],true)) throw new InvalidArgumentException('Invalid task status.');
        if (class_exists('LessonService')) { if ($status==='done') LessonService::completeTask($id); else LessonService::reopenTask($id); }
        else Db::run('UPDATE tasks SET status=? WHERE id=?',[$status,$id]);
    }
    public static function deleteTask(int $id): void {
        $task=self::task($id);
        if ($task['source']==='lesson') throw new InvalidArgumentException('Delete the linked lesson in Lessons & growth to remove its task.');
        Db::run('DELETE FROM tasks WHERE id=?',[$id]);
    }
    public static function saveHabit(?int $id,array $d): int {
        $title=self::title($d['title'] ?? ''); $notes=self::text($d['notes'] ?? '',2000);
        if ($id) { self::requireRow('planner_habits',$id); Db::run('UPDATE planner_habits SET title=?,notes=?,active=? WHERE id=?',[$title,$notes,self::flag($d,'active'),$id]); return $id; }
        return Db::run('INSERT INTO planner_habits(title,notes) VALUES (?,?)',[$title,$notes]);
    }
    public static function checkHabit(int $id,string $date): void {
        $row=self::requireRow('planner_habits',$id); valid_date($date); if ($date>date('Y-m-d')) throw new InvalidArgumentException('Habit check-ins cannot be in the future.');
        if (!$row['active']) throw new InvalidArgumentException('Reactivate this habit before checking it in.');
        if (Db::one('SELECT 1 FROM planner_habit_checks WHERE habit_id=? AND date=?',[$id,$date])) Db::run('DELETE FROM planner_habit_checks WHERE habit_id=? AND date=?',[$id,$date]);
        else Db::run('INSERT INTO planner_habit_checks(habit_id,date) VALUES (?,?)',[$id,$date]);
    }
    public static function saveRoutine(?int $id,array $d): int {
        $title=self::title($d['title'] ?? ''); $time=self::validTime($d['time'] ?? ''); $days=$d['days'] ?? [];
        if (!is_array($days) || !$days) throw new InvalidArgumentException('Choose at least one routine day.');
        $days=array_values(array_unique(array_map(fn($day)=>int_range($day,1,7),$days))); sort($days); $days=implode(',',$days);
        if ($id) { self::requireRow('planner_routines',$id); Db::run('UPDATE planner_routines SET title=?,time=?,days=?,active=? WHERE id=?',[$title,$time,$days,self::flag($d,'active'),$id]); return $id; }
        return Db::run('INSERT INTO planner_routines(title,time,days) VALUES (?,?,?)',[$title,$time,$days]);
    }
    public static function checkRoutine(int $id,string $date): void {
        $r=self::requireRow('planner_routines',$id); valid_date($date);
        if ($date>date('Y-m-d') || !$r['active'] || !in_array((string)date('N',strtotime($date)),explode(',',$r['days']),true)) throw new InvalidArgumentException('Check a routine on one of its scheduled days, up to today.');
        if (Db::one('SELECT 1 FROM planner_routine_checks WHERE routine_id=? AND date=?',[$id,$date])) Db::run('DELETE FROM planner_routine_checks WHERE routine_id=? AND date=?',[$id,$date]);
        else Db::run('INSERT INTO planner_routine_checks(routine_id,date) VALUES (?,?)',[$id,$date]);
    }
    public static function saveAppointment(?int $id,array $d): int {
        $title=self::title($d['title'] ?? ''); $start=self::validDateTime($d['starts_at'] ?? ''); $end=self::validDateTime($d['ends_at'] ?? '');
        if ($end<=$start) throw new InvalidArgumentException('Appointment end must be after its start.');
        $location=self::text($d['location'] ?? '',200); $notes=self::text($d['notes'] ?? '',2000);
        $status=$d['status'] ?? 'scheduled'; if (!is_string($status) || !in_array($status,['scheduled','done','cancelled'],true)) throw new InvalidArgumentException('Invalid appointment status.');
        $values=[$title,$start,$end,$location,$notes,int_range($d['reminder_minutes'] ?? 15,0,10080),$status];
        if ($id) { self::requireRow('planner_appointments',$id); Db::run('UPDATE planner_appointments SET title=?,starts_at=?,ends_at=?,location=?,notes=?,reminder_minutes=?,status=? WHERE id=?',[...$values,$id]); return $id; }
        return Db::run('INSERT INTO planner_appointments(title,starts_at,ends_at,location,notes,reminder_minutes,status) VALUES (?,?,?,?,?,?,?)',$values);
    }
    public static function validTime(mixed $time): string { if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time)) throw new InvalidArgumentException('Choose a valid time.'); return $time; }
    public static function validDateTime(mixed $value): string { if (!is_string($value)) throw new InvalidArgumentException('Choose a valid date and time.'); $value=str_replace('T',' ',$value); $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value); if (!$date || $date->format('Y-m-d H:i')!==$value) throw new InvalidArgumentException('Choose a valid date and time.'); return $value; }
    public static function requireRow(string $table,int $id): array { if (!in_array($table,['planner_habits','planner_routines','planner_appointments'],true)) throw new InvalidArgumentException('Invalid planner record.'); $row=Db::one('SELECT * FROM '.$table.' WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Planner record not found.'); return $row; }
    public static function remove(string $table,int $id): void { self::requireRow($table,$id); Db::run('DELETE FROM '.$table.' WHERE id=?',[$id]); }
    public static function streak(int $id): int { $dates=array_column(Db::all('SELECT date FROM planner_habit_checks WHERE habit_id=? ORDER BY date DESC',[$id]),'date'); $day=date('Y-m-d'); if (!in_array($day,$dates,true)) $day=date('Y-m-d',strtotime('-1 day')); $n=0; while (in_array($day,$dates,true)) { $n++; $day=date('Y-m-d',strtotime($day.' -1 day')); } return $n; }
    public static function upcoming(int $days=7): array { return Db::all("SELECT * FROM planner_appointments WHERE status='scheduled' AND ends_at>=? AND starts_at<=? ORDER BY starts_at",[date('Y-m-d H:i'),date('Y-m-d H:i',strtotime('+'.$days.' days'))]); }
    public static function appointmentsOn(string $date): array { valid_date($date); return Db::all("SELECT * FROM planner_appointments WHERE status='scheduled' AND starts_at<? AND ends_at>? ORDER BY starts_at",[date('Y-m-d',strtotime($date.' +1 day')).' 00:00',$date.' 00:00']); }
}

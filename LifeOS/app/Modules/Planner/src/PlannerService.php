<?php
declare(strict_types=1);
final class PlannerService {
    public static function taskList(mixed $filter='all',mixed $search='',?string $date=null): array {
        if (!is_string($filter) || !in_array($filter,['all','open','overdue','done','archived'],true)) throw new InvalidArgumentException(t('Choose a valid task filter.'));
        $search=self::text($search,200); $date=self::dateInput($date ?? date('Y-m-d'));
        $where=[$filter==='archived' ? 'archived_at IS NOT NULL' : 'archived_at IS NULL']; $params=[];
        if ($filter==='overdue') { $where[]="status='open' AND due<?"; $params[]=$date; }
        elseif (!in_array($filter,['all','archived'],true)) { $where[]='status=?'; $params[]=$filter; }
        if ($search!=='') { $where[]="title LIKE ? ESCAPE '!'"; $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%'; }
        $clause=$where ? ' WHERE '.implode(' AND ',$where) : '';
        return ['filter'=>$filter,'search'=>$search,'total'=>(int)Db::one('SELECT COUNT(*) n FROM tasks'.$clause,$params)['n'],
            'rows'=>Db::all("SELECT * FROM tasks".$clause." ORDER BY (status='done'),due IS NULL,due,id DESC LIMIT 200",$params)];
    }
    public static function taskReturnUrl(array $data): string {
        $view=$data['return_view'] ?? 'tasks';
        if (!is_string($view) || !in_array($view,['today','tasks'],true)) throw new InvalidArgumentException(t('Choose a valid return view.'));
        $date=self::dateInput($data['return_date'] ?? date('Y-m-d'));
        $filter=$data['return_filter'] ?? 'all'; $search=self::text($data['return_q'] ?? '',200);
        if (!is_string($filter) || !in_array($filter,['all','open','overdue','done','archived'],true)) throw new InvalidArgumentException(t('Choose a valid task filter.'));
        return '/planner?'.http_build_query(['view'=>$view,'date'=>$date,'filter'=>$filter,'q'=>$search]);
    }
    public const DAYS=[1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'];
    private static function text(mixed $value,int $max): string { if (!is_string($value) || mb_strlen($value)>$max) throw new InvalidArgumentException(t('Enter text of up to ').$max.' characters.'); return trim($value); }
    private static function flag(array $d,string $key): int { $v=$d[$key] ?? '0'; if (!in_array($v,['0','1',0,1,false,true],true)) throw new InvalidArgumentException(t('Invalid checkbox value.')); return in_array($v,['1',1,true],true) ? 1 : 0; }
    public static function dateInput(mixed $value,bool $optional=false): ?string { if ($optional && ($value==='' || $value===null)) return null; if (!is_string($value)) throw new InvalidArgumentException(t('Choose a valid date.')); return valid_date($value); }
    public static function title(mixed $value,int $max=200): string { if (!is_string($value) || trim($value)==='' || mb_strlen(trim($value))>$max) throw new InvalidArgumentException(t('Enter a title of up to ').$max.' characters.'); return trim($value); }
    public static function task(int $id): array { $row=Db::one('SELECT * FROM tasks WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException(t('Task not found.')); return $row; }
    public static function saveTask(?int $id,array $d): int {
        $title=self::title($d['title'] ?? ''); $due=self::dateInput($d['due'] ?? '',true);
        $current=$id ? self::task($id) : []; $priority=$d['priority'] ?? $current['priority'] ?? 'normal';
        if (!is_string($priority) || !in_array($priority,['low','normal','high'],true)) throw new InvalidArgumentException(t('Choose a valid priority.'));
        $category=array_key_exists('category',$d) ? $d['category'] : ($current['category'] ?? 'task');
        if (!is_string($category) || !in_array($category,['task','exam','todo'],true)) throw new InvalidArgumentException(t('Choose a valid task category.'));
        $estimate=int_range($d['estimate_minutes'] ?? $current['estimate_minutes'] ?? 30,5,480);
        $deadline=self::dateInput($d['deadline'] ?? $current['deadline'] ?? '',true);
        if ($deadline && $due && $due>$deadline) throw new InvalidArgumentException(t('Due date must not be after the deadline.'));
        if ($id) { $task=$current; Db::tx(function () use ($id,$title,$due,$task,$priority,$estimate,$deadline,$category) { Db::run('UPDATE tasks SET title=?,due=?,priority=?,estimate_minutes=?,deadline=?,category=? WHERE id=?',[$title,$due,$priority,$estimate,$deadline,$category,$id]); if ($task['source']==='lesson' && class_exists('LessonService')) Db::run('UPDATE lessons SET action=? WHERE task_id=?',[$title,$id]); }); return $id; }
        return Db::run('INSERT INTO tasks(title,due,source,priority,estimate_minutes,deadline,category) VALUES (?,?,?,?,?,?,?)',[$title,$due,'planner',$priority,$estimate,$deadline,$category]);
    }
    public static function taskStatus(int $id,string $status): void {
        self::task($id); if (!in_array($status,['open','done'],true)) throw new InvalidArgumentException(t('Invalid task status.'));
        if ($status==='open') Db::run('UPDATE tasks SET archived_at=NULL WHERE id=?',[$id]);
        if (class_exists('LessonService')) { if ($status==='done') LessonService::completeTask($id); else LessonService::reopenTask($id); }
        else Db::run('UPDATE tasks SET status=? WHERE id=?',[$status,$id]);
    }
    public static function deleteTask(int $id): void {
        $task=self::task($id);
        if ($task['source']==='lesson') throw new InvalidArgumentException(t('Delete the linked lesson in Lessons & growth to remove its task.'));
        Db::run('DELETE FROM tasks WHERE id=?',[$id]);
    }
    public static function saveHabit(?int $id,array $d): int {
        $title=self::title($d['title'] ?? ''); $notes=self::text($d['notes'] ?? '',2000);
        if ($id) { self::requireRow('planner_habits',$id); Db::run('UPDATE planner_habits SET title=?,notes=?,active=? WHERE id=?',[$title,$notes,self::flag($d,'active'),$id]); return $id; }
        return Db::run('INSERT INTO planner_habits(title,notes) VALUES (?,?)',[$title,$notes]);
    }
    public static function checkHabit(int $id,string $date): void {
        $row=self::requireRow('planner_habits',$id); valid_date($date); if ($date>date('Y-m-d')) throw new InvalidArgumentException(t('Habit check-ins cannot be in the future.'));
        if (!$row['active']) throw new InvalidArgumentException(t('Reactivate this habit before checking it in.'));
        if (Db::one('SELECT 1 FROM planner_habit_checks WHERE habit_id=? AND date=?',[$id,$date])) Db::run('DELETE FROM planner_habit_checks WHERE habit_id=? AND date=?',[$id,$date]);
        else Db::run('INSERT INTO planner_habit_checks(habit_id,date) VALUES (?,?)',[$id,$date]);
    }
    public static function saveRoutine(?int $id,array $d): int {
        $title=self::title($d['title'] ?? ''); $time=self::validTime($d['time'] ?? ''); $days=$d['days'] ?? [];
        if (!is_array($days) || !$days) throw new InvalidArgumentException(t('Choose at least one routine day.'));
        $days=array_values(array_unique(array_map(fn($day)=>int_range($day,1,7),$days))); sort($days); $days=implode(',',$days);
        if ($id) { self::requireRow('planner_routines',$id); Db::run('UPDATE planner_routines SET title=?,time=?,days=?,active=? WHERE id=?',[$title,$time,$days,self::flag($d,'active'),$id]); return $id; }
        return Db::run('INSERT INTO planner_routines(title,time,days) VALUES (?,?,?)',[$title,$time,$days]);
    }
    public static function checkRoutine(int $id,string $date): void {
        $r=self::requireRow('planner_routines',$id); valid_date($date);
        if ($date>date('Y-m-d') || !$r['active'] || !in_array((string)date('N',strtotime($date)),explode(',',$r['days']),true)) throw new InvalidArgumentException(t('Check a routine on one of its scheduled days, up to today.'));
        if (Db::one('SELECT 1 FROM planner_routine_checks WHERE routine_id=? AND date=?',[$id,$date])) Db::run('DELETE FROM planner_routine_checks WHERE routine_id=? AND date=?',[$id,$date]);
        else Db::run('INSERT INTO planner_routine_checks(routine_id,date) VALUES (?,?)',[$id,$date]);
    }
    public static function saveAppointment(?int $id,array $d): int {
        $current=$id ? self::requireRow('planner_appointments',$id) : [];
        $allDay=self::flag(['all_day'=>$d['all_day'] ?? $current['all_day'] ?? 0],'all_day');
        $title=self::title($d['title'] ?? '');
        if ($allDay && array_key_exists('starts_on',$d)) {
            // The form asks for the inclusive last day; stored/provider ends are exclusive.
            $start=self::dateInput($d['starts_on']).' 00:00';
            $last=self::dateInput($d['ends_on'] ?? $d['starts_on']);
            if ($last<substr($start,0,10)) throw new InvalidArgumentException(t('Appointment end must be after its start.'));
            $end=(new DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d').' 00:00';
        } else {
            $start=self::validDateTime($d['starts_at'] ?? ''); $end=self::validDateTime($d['ends_at'] ?? '');
            if ($allDay && (substr($start,11)!=='00:00' || substr($end,11)!=='00:00')) throw new InvalidArgumentException(t('All-day appointments must begin and end at midnight.'));
        }
        if ($end<=$start) throw new InvalidArgumentException(t('Appointment end must be after its start.'));
        $location=self::text($d['location'] ?? '',200); $notes=self::text($d['notes'] ?? '',2000);
        $status=$d['status'] ?? 'scheduled'; if (!is_string($status) || !in_array($status,['scheduled','done','cancelled'],true)) throw new InvalidArgumentException(t('Invalid appointment status.'));
        $values=[$title,$start,$end,$location,$notes,int_range($d['reminder_minutes'] ?? 15,0,10080),$status,$allDay];
        if ($id) { Db::run('UPDATE planner_appointments SET title=?,starts_at=?,ends_at=?,location=?,notes=?,reminder_minutes=?,status=?,all_day=? WHERE id=?',[...$values,$id]); return $id; }
        return Db::run('INSERT INTO planner_appointments(title,starts_at,ends_at,location,notes,reminder_minutes,status,all_day) VALUES (?,?,?,?,?,?,?,?)',$values);
    }
    public static function appointmentWhen(array $row): string { return !empty($row['all_day']) ? t('All day').' · '.substr($row['starts_at'],0,10).' → '.(new DateTimeImmutable($row['ends_at']))->modify('-1 day')->format('Y-m-d') : $row['starts_at'].' → '.$row['ends_at']; }
    public static function validTime(mixed $time): string { if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time)) throw new InvalidArgumentException(t('Choose a valid time.')); return $time; }
    public static function validDateTime(mixed $value): string { if (!is_string($value)) throw new InvalidArgumentException(t('Choose a valid date and time.')); $value=str_replace('T',' ',$value); $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value); if (!$date || $date->format('Y-m-d H:i')!==$value) throw new InvalidArgumentException(t('Choose a valid date and time.')); return $value; }
    public static function requireRow(string $table,int $id): array { if (!in_array($table,['planner_habits','planner_routines','planner_appointments'],true)) throw new InvalidArgumentException(t('Invalid planner record.')); $row=Db::one('SELECT * FROM '.$table.' WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException(t('Planner record not found.')); return $row; }
    public static function remove(string $table,int $id): void { self::requireRow($table,$id); Db::run('DELETE FROM '.$table.' WHERE id=?',[$id]); }
    public static function streak(int $id): int { $dates=array_column(Db::all('SELECT date FROM planner_habit_checks WHERE habit_id=? ORDER BY date DESC',[$id]),'date'); $day=date('Y-m-d'); if (!in_array($day,$dates,true)) $day=date('Y-m-d',strtotime('-1 day')); $n=0; while (in_array($day,$dates,true)) { $n++; $day=date('Y-m-d',strtotime($day.' -1 day')); } return $n; }
    public static function upcoming(int $days=7): array { return Db::all("SELECT * FROM planner_appointments WHERE status='scheduled' AND ends_at>=? AND starts_at<=? ORDER BY starts_at",[date('Y-m-d H:i'),date('Y-m-d H:i',strtotime('+'.$days.' days'))]); }
    public static function appointmentsOn(string $date): array { valid_date($date); return Db::all("SELECT * FROM planner_appointments WHERE status='scheduled' AND starts_at<? AND ends_at>? ORDER BY starts_at",[date('Y-m-d',strtotime($date.' +1 day')).' 00:00',$date.' 00:00']); }
}

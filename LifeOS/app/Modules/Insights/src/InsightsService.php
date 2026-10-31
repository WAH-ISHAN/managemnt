<?php
declare(strict_types=1);
final class InsightsService {
    public static function tables(): array { return array_column(Db::all("SELECT name FROM sqlite_master WHERE type='table'"),'name'); }
    private static function count(string $sql,array $params=[]): int { return (int)(Db::one($sql,$params)['n'] ?? 0); }
    public static function monthly(string $month): array {
        valid_month($month); $start=$month.'-01'; $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $utcStart=(new DateTimeImmutable($start))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $utcEnd=(new DateTimeImmutable($end))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $out=['month'=>$month,'money'=>null,'focus_minutes'=>null,'assignments'=>null,'completed_assignments'=>null,'exams'=>null,'habit_checkins'=>null,'applications'=>null,'lessons'=>null,'current_goals'=>null]; $tables=self::tables();
        if (class_exists('SalaryService')) $out['money']=SalaryService::overview($month)+['entries'=>self::count('SELECT COUNT(*) n FROM income WHERE date>=? AND date<?',[$start,$end])+self::count('SELECT COUNT(*) n FROM expenses WHERE date>=? AND date<?',[$start,$end])];
        if (class_exists('FocusService')) $out['focus_minutes']=FocusService::minutes($utcStart,$utcEnd);
        if (in_array('assignments',$tables,true)) { $out['assignments']=self::count('SELECT COUNT(*) n FROM assignments WHERE due_date>=? AND due_date<?',[$start,$end]); $out['completed_assignments']=self::count("SELECT COUNT(*) n FROM assignments WHERE due_date>=? AND due_date<? AND status='done'",[$start,$end]); $out['exams']=self::count('SELECT COUNT(*) n FROM exams WHERE date>=? AND date<?',[$start,$end]); }
        if (in_array('planner_habit_checks',$tables,true)) $out['habit_checkins']=self::count('SELECT COUNT(*) n FROM planner_habit_checks WHERE date>=? AND date<?',[$start,$end]);
        if (in_array('career_applications',$tables,true)) $out['applications']=self::count('SELECT COUNT(*) n FROM career_applications WHERE applied_date>=? AND applied_date<?',[$start,$end]);
        if (in_array('lessons',$tables,true)) $out['lessons']=self::count('SELECT COUNT(*) n FROM lessons WHERE created_at>=? AND created_at<?',[$utcStart,$utcEnd]);
        if (in_array('personal_goals',$tables,true)) $out['current_goals']=self::count("SELECT COUNT(*) n FROM personal_goals WHERE status='active'");
        return $out;
    }
    public static function alerts(): array {
        $calendar=[];
        if (in_array('uni_calendar_notifications',self::tables(),true)) {
            foreach (Db::all('SELECT * FROM uni_calendar_notifications ORDER BY id DESC LIMIT 50') as $item) {
                $key=hash('sha256','nibm-sync:'.$item['id']);
                $calendar[]=['id'=>$key,'title'=>$item['title'],'body'=>$item['body'],'route'=>'/uni/calendar','level'=>'info','kind'=>'calendar','created_at'=>$item['created_at'],'read'=>(bool)Db::one('SELECT 1 FROM notification_reads WHERE key=?',[$key])];
            }
        }
        $today=date('Y-m-d'); $soon=date('Y-m-d',strtotime('+2 days')); $items=[];
        $add=function (string $key,string $title,string $body,string $route,string $level='upcoming') use (&$items,$today) { $items[]=['id'=>hash('sha256',$key.':'.$today),'title'=>$title,'body'=>$body,'route'=>$route,'level'=>$level]; };
        foreach (Db::all("SELECT * FROM tasks WHERE status='open' AND due IS NOT NULL AND due<=? ORDER BY due LIMIT 12",[$soon]) as $t) $add('task:'.$t['id'],$t['due']<$today ? 'Task overdue' : 'Task due soon',$t['title'].' · '.$t['due'],'/planner',$t['due']<$today ? 'overdue' : 'upcoming');
        if (class_exists('UniService')) foreach (array_slice(UniService::upcoming(2),0,12) as $a) $add('assignment:'.$a['id'],$a['due_date']<$today ? 'Assignment overdue' : 'Assignment due soon',$a['title'].' · '.$a['due_date'],'/uni',$a['due_date']<$today ? 'overdue' : 'upcoming');
        if (class_exists('SalaryService')) foreach (SalaryService::overview(date('Y-m'))['budgets'] as $b) if ($b['monthly_budget']>0 && $b['spent']>$b['monthly_budget']) $add('budget:'.$b['id'],'Over budget: '.$b['name'],money($b['spent']).' spent against '.money($b['monthly_budget']),'/salary','warning');
        if (class_exists('FinancePlanningService')) foreach (array_slice(FinancePlanningService::dueItems(7),0,12) as $item) {
            $due=$item['due_date'] ?? $item['date'] ?? $item['due'] ?? ''; $name=$item['title'] ?? $item['name'] ?? 'Payment';
            $add('payment:'.($item['kind'] ?? $item['type'] ?? '').':'.($item['id'] ?? $name),'Payment '.($due!=='' && $due<$today ? 'overdue' : 'due soon'),$name.($due!=='' ? ' · '.$due : ''),'/salary/calendar',$due!=='' && $due<$today ? 'overdue' : 'upcoming');
        }
        $tables=self::tables();
        if (in_array('life_expiries',$tables,true)) foreach (Db::all("SELECT * FROM life_expiries WHERE status='active' AND date(expires_on,'-' || remind_days || ' days')<=? ORDER BY expires_on LIMIT 12",[$today]) as $item) {
            $add('expiry:'.$item['id'],$item['expires_on']<$today ? 'Item expired' : 'Expiry coming up',$item['title'].' · '.$item['expires_on'],'/life-admin#expiries',$item['expires_on']<$today ? 'overdue' : 'upcoming');
        }
        if (in_array('life_subscriptions',$tables,true)) foreach (Db::all("SELECT * FROM life_subscriptions WHERE status='active' AND renews_on<=? ORDER BY renews_on LIMIT 12",[date('Y-m-d',strtotime('+7 days'))]) as $item) {
            $add('subscription:'.$item['id'],'Subscription renewal',$item['name'].' · '.money($item['amount']).' · '.$item['renews_on'],'/life-admin#subscriptions',$item['renews_on']<$today ? 'overdue' : 'upcoming');
        }
        if (in_array('life_loans',$tables,true) && in_array('life_loan_payments',$tables,true)) foreach (Db::all("SELECT l.*,ROUND(l.original_amount-COALESCE(SUM(p.amount),0),2) remaining FROM life_loans l LEFT JOIN life_loan_payments p ON p.loan_id=l.id WHERE l.due_on<=? GROUP BY l.id HAVING remaining>0 ORDER BY l.due_on LIMIT 12",[$soon]) as $item) {
            $add('personal-loan:'.$item['id'],$item['direction']==='lent' ? 'Lent money follow-up' : 'Borrowed money reminder',$item['person'].' · '.money($item['remaining']).' · '.$item['due_on'],'/life-admin#loans',$item['due_on']<$today ? 'overdue' : 'upcoming');
        }
        if (in_array('personal_goals',$tables,true)) foreach (Db::all("SELECT * FROM personal_goals WHERE status='active' AND due_date<? ORDER BY due_date LIMIT 8",[$today]) as $g) $add('goal:'.$g['id'],'Goal needs attention',$g['title'].' · '.$g['due_date'],'/growth','warning');
        if (in_array('planner_appointments',$tables,true)) {
            $appointmentSql="SELECT * FROM planner_appointments WHERE status='scheduled' AND ends_at>? AND datetime(starts_at,'-' || reminder_minutes || ' minutes')<=datetime(?)";
            if (in_array('uni_calendar_events',$tables,true)) $appointmentSql.=' AND id NOT IN (SELECT appointment_id FROM uni_calendar_events WHERE appointment_id IS NOT NULL)';
            foreach (Db::all($appointmentSql.' ORDER BY starts_at LIMIT 8',[date('Y-m-d H:i'),date('Y-m-d H:i:s')]) as $a) {
            $add('appointment:'.$a['id'],'Appointment reminder',$a['title'].' · '.$a['starts_at'],'/planner');
            }
        }
        if (class_exists('CareerService')) foreach (CareerService::upcomingInterviews(1) as $a) $add('interview:'.$a['id'],'Interview coming up',($a['organization'] ?? '').' · '.($a['role'] ?? '').' · '.($a['interview_at'] ?? ''),'/career');
        if (class_exists('FocusService') && ($focus=FocusService::active()) && $focus['status']==='running' && FocusService::elapsed($focus)>=$focus['target_minutes']*60) $add('focus:'.$focus['id'],'Your focus block is complete',$focus['subject'].' · Save your session and take a break.','/focus');
        if (class_exists('WorkPlanningService')) foreach (WorkPlanningService::dueIssues(2) as $issue) $add('work-issue:'.$issue['id'],$issue['due_date']<$today?'Work item overdue':'Work item due soon',$issue['identifier'].'-'.$issue['sequence'].' ? '.$issue['title'].' ? '.$issue['due_date'],'/work/issue/'.$issue['id'],$issue['due_date']<$today?'overdue':'upcoming');
        $dismissed=array_column(Db::all('SELECT key FROM notification_dismissals'),'key');
        $readKeys=in_array('notification_reads',$tables,true) ? array_column(Db::all('SELECT key FROM notification_reads'),'key') : [];
        foreach ($items as &$item) { $item['kind']='reminder'; $item['read']=in_array($item['id'],$dismissed,true)||in_array($item['id'],$readKeys,true); } unset($item);
        return [...$calendar,...array_values(array_filter($items,fn($a)=>!in_array($a['id'],$dismissed,true)))];
    }
    public static function markRead(string $id): void {
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) throw new InvalidArgumentException('Invalid notification.');
        $alerts=self::alerts(); if (!in_array($id,array_column($alerts,'id'),true)) throw new InvalidArgumentException('Notification not found.');
        Db::run('INSERT OR IGNORE INTO notification_reads(key) VALUES (?)',[$id]);
    }
    public static function markAllRead(): void {
        if (!in_array('notification_reads',self::tables(),true)) return;
        Db::tx(function(){ foreach (self::alerts() as $alert) Db::run('INSERT OR IGNORE INTO notification_reads(key) VALUES (?)',[$alert['id']]); });
    }
    public static function review(string $month,string $wins,string $lessons,string $focus,int $score): void {
        valid_month($month); if ($score<1 || $score>10) throw new InvalidArgumentException('Choose a score from 1 to 10.');
        Db::run('INSERT INTO monthly_life_reviews(month,wins,lessons,focus,score) VALUES (?,?,?,?,?) ON CONFLICT(month) DO UPDATE SET wins=excluded.wins,lessons=excluded.lessons,focus=excluded.focus,score=excluded.score,updated_at=CURRENT_TIMESTAMP',[$month,$wins,$lessons,$focus,$score]);
    }
}

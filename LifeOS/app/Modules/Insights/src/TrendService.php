<?php
declare(strict_types=1);
/** Explainable descriptive summaries; no health diagnosis or fabricated history. */
final class TrendService {
    public static function summarize(string $month): array {
        valid_month($month); $start=$month.'-01'; $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d'); $previous=(new DateTimeImmutable($start))->modify('-1 month')->format('Y-m-d');
        $out=['period'=>[$start,$end],'spending'=>null,'habits'=>[],'patterns'=>[],'overload'=>[]];
        if (module_enabled('Salary')) {
            $current=Db::one('SELECT COUNT(*) n,COALESCE(SUM(amount),0) total,COUNT(DISTINCT date) days FROM expenses WHERE date>=? AND date<?',[$start,$end]);
            $prior=Db::one('SELECT COUNT(*) n,COALESCE(SUM(amount),0) total,COUNT(DISTINCT date) days FROM expenses WHERE date>=? AND date<?',[$previous,$start]);
            $weekend=Db::one("SELECT COUNT(DISTINCT date) days,COALESCE(SUM(amount),0) total FROM expenses WHERE date>=? AND date<? AND strftime('%w',date) IN ('0','6')",[$start,$end]);
            $weekday=Db::one("SELECT COUNT(DISTINCT date) days,COALESCE(SUM(amount),0) total FROM expenses WHERE date>=? AND date<? AND strftime('%w',date) NOT IN ('0','6')",[$start,$end]);
            $out['spending']=['current'=>$current,'previous'=>$prior,'current_complete'=>$end<=date('Y-m-d'),'change_percent'=>$end<=date('Y-m-d') && $current['n']>=3 && $prior['n']>=3 && (float)$prior['total']>0?round(((float)$current['total']/(float)$prior['total']-1)*100,1):null,'weekend_daily'=>$weekend['days']>=2?(float)$weekend['total']/(int)$weekend['days']:null,'weekday_daily'=>$weekday['days']>=3?(float)$weekday['total']/(int)$weekday['days']:null];
        }
        foreach (Db::all('SELECT h.id,h.title,h.created_at,(SELECT COUNT(*) FROM planner_habit_checks c WHERE c.habit_id=h.id AND c.date>=? AND c.date<? AND c.date<=?) current_count,(SELECT COUNT(*) FROM planner_habit_checks c WHERE c.habit_id=h.id AND c.date>=? AND c.date<?) previous_count FROM planner_habits h WHERE h.active=1',[$start,$end,date('Y-m-d'),$previous,$start]) as $h) {
            $created=(new DateTimeImmutable($h['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
            $eligible=max($start,$created); $last=min(date('Y-m-d'),(new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d'));
            $days=$eligible<=$last?(int)(new DateTimeImmutable($eligible))->diff(new DateTimeImmutable($last))->days+1:0;
            $h['eligible_days']=$days; $h['rate']=$days>=7?round((int)$h['current_count']/$days*100,1):null;
            $h['comparable']=$previous>=$created && $end<=date('Y-m-d'); $out['habits'][]=$h;
        }
        if (module_enabled('Lessons')) {
            // Exact normalized causes avoid presenting speculative correlations as discoveries.
            $groups=[];
            foreach (Db::all("SELECT id,area,root_cause,created_at FROM lessons WHERE created_at>=datetime(?,'-90 days') AND created_at<datetime(?) AND trim(COALESCE(root_cause,''))!='' ORDER BY created_at",[$end,$end]) as $row) {
                $cause=mb_strtolower(preg_replace('/\s+/u',' ',trim($row['root_cause']))); $groups[$cause][]=$row;
            }
            foreach ($groups as $cause=>$rows) if (count($rows)>=2) $out['patterns'][]=['cause'=>$cause,'count'=>count($rows),'areas'=>array_values(array_unique(array_column($rows,'area'))),'ids'=>array_column($rows,'id'),'first'=>$rows[0]['created_at'],'last'=>$rows[count($rows)-1]['created_at'],'suggestion'=>'Review the repeated cause and choose one small preventive action.'];
        }
        $capacity=(int)Db::setting('wellbeing_daily_minutes','480'); $taskLimit=(int)Db::setting('wellbeing_open_tasks','12');
        if (Db::setting('wellbeing_enabled')==='1') {
            $today=date('Y-m-d');
            $due=Db::one("SELECT COUNT(*) n,COALESCE(SUM(estimate_minutes),0) minutes FROM tasks WHERE status='open' AND archived_at IS NULL AND due<=?",[$today]);
            $appointmentMinutes=0; $lastEnd=0; $dayStart=strtotime($today.' 00:00'); $dayEnd=strtotime($today.' +1 day');
            foreach (PlannerService::appointmentsOn($today) as $a) {
                $start=max($dayStart,strtotime($a['starts_at']),$lastEnd); $end=min($dayEnd,strtotime($a['ends_at']));
                $appointmentMinutes+=max(0,$end-$start)/60; $lastEnd=max($lastEnd,$end);
            }
            if ((int)$due['n']>$taskLimit || (int)$due['minutes']+$appointmentMinutes>$capacity) $out['overload'][]=['title'=>t('Your planned day may be overloaded'),'body'=>$due['n'].' due/overdue tasks, '.$due['minutes'].' estimated task minutes and '.round($appointmentMinutes).' appointment minutes versus your '.$capacity.' minute capacity. Consider moving work or taking a break.'];
            if (module_enabled('Focus')) {
                $days=Db::all("SELECT date(completed_at,'+330 minutes') day,SUM(elapsed_seconds)/60.0 minutes FROM focus_sessions WHERE status='complete' AND kind='study' AND date(completed_at,'+330 minutes')>=? AND date(completed_at,'+330 minutes')<=? GROUP BY day HAVING minutes>?",[date('Y-m-d',strtotime('-2 days')),$today,$capacity]);
                if (count($days)>=3) $out['overload'][]=['title'=>t('Sustained long focus days'),'body'=>'Recorded focus exceeded your daily capacity for three days. Check your workload and make room for rest.'];
            }
        }
        return $out;
    }
    public static function configure(array $d): void {
        $minutes=int_range($d['daily_minutes'] ?? '',30,1440); $tasks=int_range($d['open_tasks'] ?? '',1,200);
        Db::tx(function () use ($d,$minutes,$tasks) { Db::set('wellbeing_enabled',isset($d['enabled'])?'1':'0'); Db::set('wellbeing_daily_minutes',(string)$minutes); Db::set('wellbeing_open_tasks',(string)$tasks); });
    }
}
